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
        wp_send_json_error(['message' => __( 'Permission denied', 'power-importer-pro')]);
        return; // Added return
    }
    
    if (!isset($_FILES['import_csv_files']) || empty($_FILES['import_csv_files']['name'][0])) {
        wp_send_json_error(['message' => __( 'No files were uploaded', 'power-importer-pro')]);
        return; // Added return
    }
    
    $upload_dir = wp_upload_dir();
    $pip_dir = $upload_dir['basedir'] . '/' . PIP_UPLOAD_DIR_NAME;
    
    if (!file_exists($pip_dir) || !is_writable($pip_dir)) {
        wp_send_json_error(['message' => sprintf(__( 'Upload directory is not writable: %s', 'power-importer-pro'), esc_html($pip_dir) )]);
        return; // Added return
    }
    
    $files = $_FILES['import_csv_files'];
    $allowed_types = ['text/csv', 'text/plain', 'application/vnd.ms-excel']; // These are MIME types, generally not for direct user display
    $uploaded_jobs = [];
    $errors = [];
    
    foreach ($files['name'] as $key => $name) {
        if ($files['error'][$key] !== UPLOAD_ERR_OK) {
            // Consider using wp_get_upload_error_message() if available, or map codes to translatable strings
            $errors[] = sprintf(__( 'File %s: Upload error (code: %d)', 'power-importer-pro'), esc_html($name), $files['error'][$key]);
            continue;
        }
        
        if (!in_array($files['type'][$key], $allowed_types)) {
            $errors[] = sprintf(__( 'File %s: Invalid file type (%s)', 'power-importer-pro'), esc_html($name), esc_html($files['type'][$key]));
            continue;
        }
        
        $filename = 'import-' . time() . '-' . sanitize_file_name($name); // Internal name
        $filepath = $pip_dir . '/' . $filename;
        
        if (move_uploaded_file($files['tmp_name'][$key], $filepath)) {
            $job_id = pip_db()->create_job(basename($filepath), $filepath);
            if ($job_id) {
                $uploaded_jobs[] = [
                    'id' => $job_id,
                    'filename' => $name, // Original name for display
                    'status' => 'uploaded'
                ];
            } else {
                $errors[] = sprintf(__( 'File %s: Database error while creating job record.', 'power-importer-pro'), esc_html($name));
            }
        } else {
            $errors[] = sprintf(__( 'File %s: Could not be moved to the uploads directory.', 'power-importer-pro'), esc_html($name));
        }
    }
    
    if (!empty($uploaded_jobs)) {
        $message = sprintf( _n( '%d file uploaded successfully.', '%d files uploaded successfully.', count($uploaded_jobs), 'power-importer-pro' ), count($uploaded_jobs) );
        if (!empty($errors)) {
            $message .= ' ' . sprintf( _n( '%d file had an error.', '%d files had errors.', count($errors), 'power-importer-pro' ), count($errors) );
        }
        wp_send_json_success([
            'message' => $message,
            'jobs' => $uploaded_jobs,
            'errors' => $errors // These are already translated error strings
        ]);
    } else {
        wp_send_json_error([
            'message' => __( 'No files were uploaded successfully.', 'power-importer-pro'),
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
        wp_send_json_error(['message' => __( 'Permission denied', 'power-importer-pro')]);
        return; // Added return
    }
    
    $upload_dir = wp_upload_dir();
    $pip_dir = $upload_dir['basedir'] . '/' . PIP_UPLOAD_DIR_NAME;
    
    if (!file_exists($pip_dir)) {
        wp_send_json_error(['message' => sprintf(__( 'Upload directory does not exist: %s', 'power-importer-pro'), esc_html($pip_dir) )]);
        return; // Added return
    }
    
    // 扫描目录中的CSV文件
    $files = glob($pip_dir . '/*.{csv,CSV,txt,TXT}', GLOB_BRACE);
    $new_jobs = [];
    $existing_files = [];
    $errors = [];
    
    // 获取已存在的文件路径
    global $wpdb;
    $existing_paths = $wpdb->get_col(
        $wpdb->prepare("SELECT file_path FROM {$wpdb->prefix}pip_import_jobs WHERE file_path IS NOT NULL") // Ensure file_path is not null
    );
    
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        
        // 检查文件是否已经在数据库中
        if (in_array($filepath, $existing_paths)) {
            $existing_files[] = $filename;
            continue;
        }
        
        // 验证文件是否为有效的CSV
        if (!is_valid_csv_file($filepath)) { // is_valid_csv_file already checks headers
            $errors[] = sprintf(__( 'File %s: Invalid CSV format or missing required headers.', 'power-importer-pro'), esc_html($filename));
            continue;
        }
        
        // 创建新任务
        $job_id = pip_db()->create_job($filename, $filepath);
        if ($job_id) {
            $new_jobs[] = [
                'id' => $job_id,
                'filename' => $filename,
                'status' => 'pending' // New files are pending validation
            ];
        } else {
            $errors[] = sprintf(__( 'File %s: Database error creating job record.', 'power-importer-pro'), esc_html($filename));
        }
    }
    
    $message_parts = [];
    if (!empty($new_jobs)) {
        $message_parts[] = sprintf( _n( '%d new file found and added.', '%d new files found and added.', count($new_jobs), 'power-importer-pro' ), count($new_jobs) );
    }
    if (!empty($existing_files)) {
        $message_parts[] = sprintf( _n( '%d file already exists in database.', '%d files already exist in database.', count($existing_files), 'power-importer-pro' ), count($existing_files) );
    }
    if (!empty($errors)) {
         $message_parts[] = sprintf( _n( '%d file had an error.', '%d files had errors.', count($errors), 'power-importer-pro' ), count($errors) );
    }
    
    if (empty($new_jobs) && empty($existing_files) && empty($errors) ) { // If truly nothing happened
        wp_send_json_error(['message' => __( 'No new, existing, or erroneous CSV files found in upload directory.', 'power-importer-pro')]);
        return; // Added return
    }
    
    wp_send_json_success([
        'message' => implode(' ', $message_parts),
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

    $headers = fgetcsv($handle);
    fclose($handle);

    if ($headers === false || empty($headers)) {
        // Cannot read headers or file is empty
        return false;
    }

    // Trim header values
    $actual_headers = array_map('trim', $headers);

    // Define required headers
    $required_headers = ['Name', 'SKU', 'Type']; // Case-sensitive, ensure these match expected CSV format

    // Check if all required headers are present
    $missing_headers = array_diff($required_headers, $actual_headers);

    if (!empty($missing_headers)) {
        // Log or store information about missing headers if desired, for more detailed error reporting
        // error_log("CSV Validation: File {$filepath} is missing headers: " . implode(', ', $missing_headers));
        return false; // Required headers are missing
    }
    
    // Original check for more than one column (implicitly covered if required headers are specific enough)
    // but can be kept as an additional safeguard or if required_headers is minimal.
    // This check becomes less critical if $required_headers has multiple items,
    // as the array_diff check would already ensure multiple specific headers are present.
    // However, if $required_headers could be e.g. just ['SKU'], then this check is still useful.
    if (count($actual_headers) <= 1 && count($required_headers) > 1) {
         // This case implies that even if one required header was found (e.g. if $required_headers was just ['SKU']),
         // the CSV itself doesn't have a multi-column structure, which is usually expected.
        return false;
    }

    return true; // All checks passed
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
    // This notice might be repurposed or removed if Action Scheduler is no longer a consideration at all.
    // For now, it indicates the shift in processing method.
    echo '<div class="notice notice-info"><p>' . esc_html__( 'Power Importer Pro now uses a built-in AJAX and background processing (loopback) system.', 'power-importer-pro' ) . '</p></div>';
}

/**
 * 新增：重置卡住的任务 (Now primarily for jobs stuck in processing, not just scheduled ones)
 */
function pip_ajax_reset_job_callback() {
    check_ajax_referer('pip_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __( 'Permission denied', 'power-importer-pro')]);
        return; // Added return
    }
    
    $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
    if (!$job_id) {
        wp_send_json_error(['message' => __( 'Invalid job ID', 'power-importer-pro')]);
        return; // Added return
    }
    
    // 重置任务状态
    $reset_data = [
        'status' => 'pending', // Reset to pending for re-validation and re-processing
        'processed_rows' => 0,
        'total_rows' => 0, // Reset total_rows to ensure re-validation if structure changed
        'started_at' => null,
        'finished_at' => null,
        'error_message' => null // Clear previous errors
    ];
    
    $updated = pip_db()->update_job($job_id, $reset_data);

    if ($updated !== false) { // update_job returns number of rows updated or false on error
        pip_db()->add_log($job_id, 'INFO', __('Job has been reset to pending status by user.', 'power-importer-pro'));
        wp_send_json_success([
            'message' => __( 'Job has been reset successfully.', 'power-importer-pro'),
            'job_id' => $job_id
        ]);
    } else {
        pip_db()->add_log($job_id, 'ERROR', __('Failed to reset job status in database.', 'power-importer-pro'));
        wp_send_json_error(['message' => __( 'Failed to reset job.', 'power-importer-pro')]);
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