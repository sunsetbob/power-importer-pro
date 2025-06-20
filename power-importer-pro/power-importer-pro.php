<?php
/**
 * Plugin Name:       Power Importer Pro
 * Plugin URI:        https://yourwebsite.com/
 * Description:       A professional tool to import WooCommerce products from a CSV file with background processing and detailed logging.
 * Version:           1.5.0 (Stable & Refactored)
 * Author:            Your Name
 * Author URI:        https://yourwebsite.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       power-importer-pro
 * Domain Path:       /languages
 */

// 强制使用直接文件I/O，解决权限问题
if ( ! defined('FS_METHOD') ) {
    define('FS_METHOD', 'direct');
}

// 防止直接访问文件
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 定义插件常量
define( 'PIP_VERSION', '1.5.0' );
define( 'PIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIP_UPLOAD_DIR_NAME', 'power-importer-pro-file' );

/**
 * 加载插件的所有文件
 */
require_once PIP_PLUGIN_DIR . 'includes/functions.php';
require_once PIP_PLUGIN_DIR . 'includes/class-database.php';
require_once PIP_PLUGIN_DIR . 'includes/class-importer.php';
require_once PIP_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once PIP_PLUGIN_DIR . 'includes/class-ajax-processor.php';

/**
 * 初始化插件和所有钩子
 */
function pip_init() {
    // 不再检查Action Scheduler依赖
    // 注释掉原来的Action Scheduler检查
    /*
    if ( ! function_exists('as_enqueue_async_action') ) {
        add_action( 'admin_notices', 'pip_show_dependencies_notice' );
        return;
    }
    */
    
    // 初始化后台页面
    if ( is_admin() ) {
        new PIP_Admin_Page();
        
        // 初始化AJAX处理器
        new PIP_Ajax_Processor();
    }
    
    // 移除Action Scheduler钩子
    // add_action( 'pip_process_import_file', 'pip_run_import_task_callback', 10, 1 );

    // 保留现有的AJAX钩子（用于向后兼容）
    add_action( 'wp_ajax_pip_get_jobs_table', 'pip_ajax_get_jobs_table_callback' );
    add_action( 'wp_ajax_pip_job_action', 'pip_ajax_handle_job_action_callback' );
    add_action( 'wp_ajax_pip_clear_jobs', 'pip_ajax_handle_clear_jobs_callback' );
    
    // 新增文件上传AJAX处理
    add_action( 'wp_ajax_pip_upload_files', 'pip_ajax_upload_files_callback' );
    
    // 新增文件扫描AJAX处理
    add_action( 'wp_ajax_pip_scan_files', 'pip_ajax_scan_files_callback' );
    
    // 新增重置任务AJAX处理
    add_action( 'wp_ajax_pip_reset_job', 'pip_ajax_reset_job_callback' );
}
add_action( 'plugins_loaded', 'pip_init' );

/**
 * 新增：处理文件上传的AJAX回调
 */
function pip_ajax_upload_files_callback() {
    check_ajax_referer('pip_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    if (!isset($_FILES['import_csv_files']) || empty($_FILES['import_csv_files']['name'][0])) {
        wp_send_json_error(['message' => 'No files were uploaded']);
    }
    
    $upload_dir = wp_upload_dir();
    $pip_dir = $upload_dir['basedir'] . '/' . PIP_UPLOAD_DIR_NAME;
    
    if (!file_exists($pip_dir) || !is_writable($pip_dir)) {
        wp_send_json_error(['message' => 'Upload directory is not writable: ' . $pip_dir]);
    }
    
    $files = $_FILES['import_csv_files'];
    $allowed_types = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];
    $uploaded_jobs = [];
    $errors = [];
    
    foreach ($files['name'] as $key => $name) {
        if ($files['error'][$key] !== UPLOAD_ERR_OK) {
            $errors[] = "File {$name}: Upload error";
            continue;
        }
        
        if (!in_array($files['type'][$key], $allowed_types)) {
            $errors[] = "File {$name}: Invalid file type";
            continue;
        }
        
        $filename = 'import-' . time() . '-' . sanitize_file_name($name);
        $filepath = $pip_dir . '/' . $filename;
        
        if (move_uploaded_file($files['tmp_name'][$key], $filepath)) {
            $job_id = pip_db()->create_job(basename($filepath), $filepath);
            if ($job_id) {
                $uploaded_jobs[] = [
                    'id' => $job_id,
                    'filename' => $name,
                    'status' => 'uploaded'
                ];
            } else {
                $errors[] = "File {$name}: Database error";
            }
        } else {
            $errors[] = "File {$name}: File move failed";
        }
    }
    
    if (!empty($uploaded_jobs)) {
        wp_send_json_success([
            'message' => count($uploaded_jobs) . ' files uploaded successfully',
            'jobs' => $uploaded_jobs,
            'errors' => $errors
        ]);
    } else {
        wp_send_json_error([
            'message' => 'No files were uploaded successfully',
            'errors' => $errors
        ]);
    }
}

/**
 * 新增：扫描上传目录中的CSV文件
 */
function pip_ajax_scan_files_callback() {
    check_ajax_referer('pip_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    $upload_dir = wp_upload_dir();
    $pip_dir = $upload_dir['basedir'] . '/' . PIP_UPLOAD_DIR_NAME;
    
    if (!file_exists($pip_dir)) {
        wp_send_json_error(['message' => 'Upload directory does not exist: ' . $pip_dir]);
    }
    
    // 扫描目录中的CSV文件
    $files = glob($pip_dir . '/*.{csv,CSV,txt,TXT}', GLOB_BRACE);
    $new_jobs = [];
    $existing_files = [];
    $errors = [];
    
    // 获取已存在的文件路径
    global $wpdb;
    $existing_paths = $wpdb->get_col(
        "SELECT file_path FROM {$wpdb->prefix}pip_import_jobs"
    );
    
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        
        // 检查文件是否已经在数据库中
        if (in_array($filepath, $existing_paths)) {
            $existing_files[] = $filename;
            continue;
        }
        
        // 验证文件是否为有效的CSV
        if (!is_valid_csv_file($filepath)) {
            $errors[] = "File {$filename}: Invalid CSV format";
            continue;
        }
        
        // 创建新任务
        $job_id = pip_db()->create_job($filename, $filepath);
        if ($job_id) {
            $new_jobs[] = [
                'id' => $job_id,
                'filename' => $filename,
                'status' => 'pending'
            ];
        } else {
            $errors[] = "File {$filename}: Database error";
        }
    }
    
    $message = '';
    if (!empty($new_jobs)) {
        $message .= count($new_jobs) . ' new files found and added. ';
    }
    if (!empty($existing_files)) {
        $message .= count($existing_files) . ' files already exist. ';
    }
    if (!empty($errors)) {
        $message .= count($errors) . ' files had errors.';
    }
    
    if (empty($new_jobs) && empty($existing_files)) {
        wp_send_json_error(['message' => 'No CSV files found in upload directory']);
    }
    
    wp_send_json_success([
        'message' => trim($message),
        'new_jobs' => $new_jobs,
        'existing_files' => $existing_files,
        'errors' => $errors,
        'scanned_directory' => $pip_dir
    ]);
}

/**
 * 验证CSV文件格式
 */
function is_valid_csv_file($filepath) {
    if (!file_exists($filepath) || !is_readable($filepath)) {
        return false;
    }
    
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return false;
    }
    
    // 读取第一行检查是否为有效的CSV
    $first_line = fgetcsv($handle);
    fclose($handle);
    
    return $first_line !== false && count($first_line) > 1;
}

/**
 * 插件激活时运行的函数
 */
function pip_plugin_activation() {
    // 1. 创建上传目录
    $upload_dir = wp_upload_dir();
    $pip_dir = $upload_dir['basedir'] . '/' . PIP_UPLOAD_DIR_NAME;
    if ( ! file_exists( $pip_dir ) ) {
        wp_mkdir_p( $pip_dir );
        if ( ! file_exists($pip_dir . '/.htaccess') ) { file_put_contents($pip_dir . '/.htaccess', 'deny from all'); }
        if ( ! file_exists($pip_dir . '/index.html') ) { file_put_contents($pip_dir . '/index.html', '<!-- Silence is golden. -->'); }
    }
    
    // 2. 创建或更新数据库表
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    // 【SQL 修复】修正了所有默认值错误
    $jobs_table_name = $wpdb->prefix . 'pip_import_jobs';
    $sql_jobs = "CREATE TABLE $jobs_table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        file_name VARCHAR(255) NOT NULL,
        file_path TEXT NOT NULL,
        total_rows INT(11) UNSIGNED NOT NULL DEFAULT 0,
        processed_rows INT(11) UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        started_at DATETIME NULL DEFAULT NULL,
        finished_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta( $sql_jobs );

    $logs_table_name = $wpdb->prefix . 'pip_import_logs';
    $sql_logs = "CREATE TABLE $logs_table_name (
        log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        job_id BIGINT(20) UNSIGNED NOT NULL,
        log_level VARCHAR(20) NOT NULL DEFAULT 'INFO',
        message TEXT NOT NULL,
        log_timestamp DATETIME NOT NULL,
        PRIMARY KEY  (log_id),
        KEY job_id (job_id),
        KEY log_level (log_level)
    ) $charset_collate;";
    dbDelta( $sql_logs );
}
register_activation_hook( __FILE__, 'pip_plugin_activation' );

/**
 * 修改：依赖提示信息
 */
function pip_show_dependencies_notice() {
    echo '<div class="notice notice-info"><p>' . __( 'Power Importer Pro is now using AJAX-based processing and no longer requires Action Scheduler.', 'power-importer-pro' ) . '</p></div>';
}

/**
 * 新增：重置卡住的任务
 */
function pip_ajax_reset_job_callback() {
    check_ajax_referer('pip_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
    if (!$job_id) {
        wp_send_json_error(['message' => 'Invalid job ID']);
    }
    
    // 重置任务状态
    $updated = pip_db()->update_job($job_id, [
        'status' => 'pending',
        'processed_rows' => 0,
        'started_at' => null,
        'finished_at' => null
    ]);
    
    if ($updated) {
        // 记录重置日志
        pip_db()->add_log($job_id, 'INFO', 'Job has been reset to pending status');
        
        wp_send_json_success([
            'message' => 'Job has been reset successfully',
            'job_id' => $job_id
        ]);
    } else {
        wp_send_json_error(['message' => 'Failed to reset job']);
    }
}

/**
 * 添加自定义cron间隔
 */
function pip_add_cron_intervals($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display' => __('Every Minute', 'power-importer-pro')
    );
    return $schedules;
}
add_filter('cron_schedules', 'pip_add_cron_intervals');