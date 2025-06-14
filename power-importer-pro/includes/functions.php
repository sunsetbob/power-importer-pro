<?php
/**
 * 插件的全局函数和回调
 */
if ( ! defined( 'ABSPATH' ) ) exit;

//======================================================================
// 1. 核心后台任务处理
//======================================================================

/**
 * 【核心】创建一个全局的数据库对象实例，确保只创建一次
 */
function pip_db() {
    static $db_instance;
    if ( null === $db_instance ) {
        $db_instance = new PIP_Database();
    }
    return $db_instance;
}

/**
 * Action Scheduler 任务的回调函数
 * @param int $job_id
 */
function pip_run_import_task_callback( $job_id ) {
    $job = pip_db()->get_job($job_id);

    if ( ! $job || empty($job->file_path) || ! file_exists($job->file_path) ) {
        error_log('Power Importer Pro Task Error: Could not find job or filepath in database for job_id: ' . $job_id);
        if ($job_id) { pip_db()->update_job($job_id, ['status' => 'failed', 'finished_at' => current_time('mysql', 1)]); }
        return;
    }
    
    // 确保在后台任务中，所有需要的文件都被加载
    require_once( ABSPATH . 'wp-admin/includes/taxonomy.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-includes/kses.php' );

    $importer = new PIP_Importer( $job->file_path, $job_id );
    $importer->run();
}

//======================================================================
// 2. AJAX 请求处理
//======================================================================

/**
 * AJAX回调：获取最新的任务表格HTML
 */
function pip_ajax_get_jobs_table_callback() {
    // 添加nonce检查
    check_ajax_referer('pip_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    try {
        ob_start();
        // 临时创建一个Admin Page实例，只为了调用它的渲染方法
        $admin_page = new PIP_Admin_Page();
        $admin_page->render_jobs_table_content();
        $html = ob_get_clean();
        
        wp_send_json_success(['html' => $html]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Failed to load jobs table: ' . $e->getMessage()]);
    }
}

/**
 * AJAX回调：处理单个任务的操作（删除、重试、取消）
 */
function pip_ajax_handle_job_action_callback() {
    check_ajax_referer( 'pip_ajax_nonce', 'nonce' );
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission Denied.']);
    }
    $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
    $action = isset($_POST['job_action']) ? sanitize_key($_POST['job_action']) : '';
    if (!$job_id || !$action) {
        wp_send_json_error(['message' => 'Invalid Job ID or Action.']);
    }

    switch ($action) {
        case 'delete':
            pip_db()->delete_job_and_logs($job_id);
            wp_send_json_success( ['message' => __( 'Job and its logs have been deleted.', 'power-importer-pro' )] );
            break;
        case 'retry':
            $action_id = as_enqueue_async_action( 'pip_process_import_file', [ $job_id ], 'power-importer-pro-group' );
            if ($action_id) {
                pip_db()->update_job($job_id, ['status' => 'pending', 'processed_rows' => 0, 'started_at' => null, 'finished_at' => null]);
                wp_send_json_success( ['message' => __( 'Job has been re-scheduled.', 'power-importer-pro' )] );
            } else {
                wp_send_json_error( ['message' => __( 'Failed to re-schedule the job.', 'power-importer-pro' )] );
            }
            break;
        case 'cancel':
            $actions = as_get_scheduled_actions( [ 'hook' => 'pip_process_import_file', 'args' => [ $job_id ], 'status' => [ 'pending', 'in-progress' ] ], 'ids' );
            if ( ! empty($actions) ) { foreach ($actions as $action_id) { as_cancel_action( $action_id ); } }
            pip_db()->update_job($job_id, ['status' => 'cancelled', 'finished_at' => current_time('mysql', 1)]);
            wp_send_json_success( ['message' => __( 'Job has been cancelled.', 'power-importer-pro' )] );
            break;
    }
    wp_send_json_error( ['message' => 'Unknown action.'] );
}

/**
 * AJAX回调：一键清理所有已结束的任务
 */
function pip_ajax_handle_clear_jobs_callback() {
    check_ajax_referer( 'pip_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( ['message' => 'Permission Denied.'] );
    }
    pip_db()->clear_old_jobs();
    wp_send_json_success( ['message' => __( 'All completed, failed, and cancelled jobs have been cleared.', 'power-importer-pro' )] );
}

//======================================================================
// 3. 图片处理辅助函数 (保持不变)
//======================================================================

function pip_remote_file($url, $file = "", $timeout = 60) {
    if ( ! defined('FS_METHOD') ) { define('FS_METHOD', 'direct'); }
    WP_Filesystem(); global $wp_filesystem;
    $file = empty($file) ? pathinfo($url, PATHINFO_BASENAME) : $file;
    $dir  = pathinfo($file, PATHINFO_DIRNAME);
    if ( ! $wp_filesystem->is_dir( $dir ) ) { if ( ! $wp_filesystem->mkdir( $dir, 0755, true ) ) { error_log("Power Importer Pro: WP_Filesystem failed to create directory: {$dir}"); return false; } }
    $url = trim(str_replace(" ", "%20", $url));
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        $temp = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curl_error = curl_error($ch); curl_close($ch);
        if ($http_code == 200 && !empty($temp)) { if ( $wp_filesystem->put_contents( $file, $temp, FS_CHMOD_FILE ) ) { return $file; } else { error_log("Power Importer Pro: WP_Filesystem failed to write file to disk: {$file}"); return false; } } else { error_log("Power Importer Pro: cURL failed to download {$url}. HTTP Code: {$http_code}. cURL Error: {$curl_error}"); return false; }
    } elseif (ini_get('allow_url_fopen')) {
        $options = [ 'http' => [ 'method' => "GET", 'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n" ] ];
        $context = stream_context_create($options); $temp = @file_get_contents($url, false, $context);
        if ($temp !== false) { if ($wp_filesystem->put_contents( $file, $temp, FS_CHMOD_FILE )) { return $file; } }
    }
    error_log("Power Importer Pro: All download methods failed for {$url}."); return false;
}

function pip_wp_save_img($url = '', $filename ='', $post_title_for_alt = '') {
    $url = trim($url); if (empty($url)) return false;
    if ( ! defined('FS_METHOD') ) { define('FS_METHOD', 'direct'); }
    WP_Filesystem();
    $image_resize_threshold = 1000; $webp_quality = 75;
    $uploads = wp_upload_dir(time());
    if ($uploads['error']) {
        global $wp_filesystem;
        preg_match('/Unable to create directory (.*?)\./', $uploads['error'], $matches);
        if (isset($matches[1])) {
            $path_to_create = $matches[1];
            if (!$wp_filesystem->mkdir($path_to_create, 0755, true)) { error_log('Power Importer Pro: Fallback mkdir failed for: ' . $path_to_create); return false; }
            $uploads = wp_upload_dir(time());
            if ($uploads['error']) { error_log('Power Importer Pro: wp_upload_dir() still fails after manual directory creation: ' . $uploads['error']); return false; }
        } else { error_log('Power Importer Pro: wp_upload_dir() generic error: ' . $uploads['error']); return false; }
    }
    $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION)); $file_extension = in_array($extension, ['jpg', 'jpeg', 'png', 'gif']) ? $extension : 'jpg';
    $tmp_filename = uniqid('pip_') . '.' . $file_extension; $tmp_filepath = $uploads["path"] . "/" . $tmp_filename;
    if (!pip_remote_file($url, $tmp_filepath)) { return false; }
    $webp_filename = (!empty($filename) ? sanitize_file_name($filename) : uniqid('pip_')) . '.webp'; $webp_filepath = $uploads["path"] . "/" . $webp_filename;
    $image_editor = wp_get_image_editor($tmp_filepath);
    if (!is_wp_error($image_editor)) {
        $image_editor->resize($image_resize_threshold, $image_resize_threshold, false); $image_editor->set_quality($webp_quality); $saved = $image_editor->save($webp_filepath, 'image/webp');
        @unlink($tmp_filepath);
        if (!is_wp_error($saved) && !empty($saved) && file_exists($saved['path'])) {
            $attachment = [ 'post_mime_type' => 'image/webp', 'guid' => $uploads["url"] . "/" . basename($saved['path']), 'post_parent' => 0, 'post_title' => !empty($post_title_for_alt) ? $post_title_for_alt : preg_replace('/\.[^.]+$/', '', basename($saved['path'])), 'post_content' => '', ];
            $thumbnail_id = wp_insert_attachment($attachment, $saved['path'], 0);
            if (!is_wp_error($thumbnail_id)) {
                update_post_meta($thumbnail_id, '_wp_attachment_image_alt', $attachment['post_title']);
                $attach_data = wp_generate_attachment_metadata($thumbnail_id, $saved['path']);
                wp_update_attachment_metadata($thumbnail_id, $attach_data);
                return $thumbnail_id;
            }
        }
    }
    if(file_exists($tmp_filepath)) @unlink($tmp_filepath);
    return false;
}