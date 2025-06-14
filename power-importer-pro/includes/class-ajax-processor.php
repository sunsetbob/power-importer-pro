<?php
/**
 * AJAX分片处理器类 - Refactored for wp_remote_post async processing
 * Implements断点续传和批量处理功能
 */
if (!defined('ABSPATH')) exit;

class PIP_Ajax_Processor {
    
    const BATCH_SIZE = 10; // 每批处理的行数
    const PROCESS_DELAY = 1000; // 批次间延迟(毫秒)
    const ASYNC_TIMEOUT_SECONDS = 300; // 后台异步处理的超时时间
    const ASYNC_MEMORY_LIMIT = '512M'; // 后台异步处理的内存限制

    public function __construct() {
        // 注册AJAX钩子
        add_action('wp_ajax_pip_start_batch_import', [$this, 'start_batch_import']);
        add_action('wp_ajax_pip_process_batch', [$this, 'process_batch']);
        add_action('wp_ajax_pip_pause_import', [$this, 'pause_import']);
        add_action('wp_ajax_pip_resume_import', [$this, 'resume_import']);
        add_action('wp_ajax_pip_cancel_import', [$this, 'cancel_import']);
        add_action('wp_ajax_pip_get_import_status', [$this, 'get_import_status']);
        add_action('wp_ajax_pip_validate_csv', [$this, 'validate_csv']);
        
        // Background processing hooks
        add_action('wp_ajax_pip_enable_background_mode', [$this, 'enable_background_mode']);
        add_action('wp_ajax_pip_async_background_process', [$this, 'async_background_process']); // Target for wp_remote_post
        // Removed WP-Cron and Heartbeat related hooks
    }
    
    /**
     * 启用后台处理模式 - 简化为仅使用 wp_remote_post 异步方法
     */
    public function enable_background_mode() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'power-importer-pro')]);
            return;
        }
        
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) {
            wp_send_json_error(['message' => __('Invalid job ID.', 'power-importer-pro')]);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if (!$job) {
            wp_send_json_error(['message' => __('Job not found.', 'power-importer-pro')]);
            return;
        }

        // Prevent re-queueing if already processing or finished
        $non_requeueable_statuses = ['queued_async', 'running_async', 'completed', 'failed'];
        if (in_array($job->status, $non_requeueable_statuses)) {
            pip_db()->add_log($job_id, sprintf(__('Attempt to enable background mode for job in status '%s' was prevented.', 'power-importer-pro'), $job->status), 'WARNING');
            wp_send_json_error(['message' => sprintf(__('Job is already processing, scheduled, or finished (%s).', 'power-importer-pro'), $job->status)]);
            return;
        }
        
        pip_db()->update_job($job_id, [
            'status' => 'queued_async',
            'started_at' => current_time('mysql', 1) // Mark when background mode was enabled/re-enabled
        ]);
        
        pip_db()->add_log($job_id, __('Job queued for background processing via async request.', 'power-importer-pro'), 'INFO');

        // Trigger the immediate background process
        $this->immediate_background_process($job_id); // This function will send its own JSON response
    }
    
    /**
     * 立即后台处理（使用 wp_remote_post 异步调用）
     */
    private function immediate_background_process($job_id) {
        $async_nonce = wp_create_nonce('pip_async_bg_nonce');

        $body = [
            'action' => 'pip_async_background_process',
            'job_id' => $job_id,
            '_ajax_nonce'  => $async_nonce, // Use _ajax_nonce for check_ajax_referer in the target
        ];

        $url = admin_url('admin-ajax.php');
        $args = [
            'timeout'   => 1, // Very short timeout, just to dispatch
            'blocking'  => false, // Makes the request non-blocking
            'body'      => $body,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'cookies'   => $_COOKIE // Pass along cookies for authentication if needed by the AJAX handler
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            pip_db()->add_log($job_id, sprintf(__('Failed to dispatch async background process. WP_Error: %s', 'power-importer-pro'), $error_message), 'ERROR');
            pip_db()->update_job($job_id, ['status' => 'failed', 'error_message' => sprintf(__('Failed to dispatch background task: %s', 'power-importer-pro'), $error_message)]);
            wp_send_json_error([
                'message' => sprintf(__('Failed to dispatch background task: %s', 'power-importer-pro'), $error_message),
                'job_status_updated' => true // Status was updated to failed
            ]);
            return;
        }

        // For non-blocking, we might not get a 200, but we shouldn't get a WP_Error.
        // The actual success/failure of the job will be handled by the async process itself.
        pip_db()->add_log($job_id, __('Async background process successfully dispatched.', 'power-importer-pro'), 'INFO');
        wp_send_json_success([
            'message' => __('Background processing initiated. The job will run on the server.', 'power-importer-pro'),
            'job_id' => $job_id,
            'status' => 'queued_async' // Confirming the new status
        ]);
    }
    
    /**
     * 异步后台处理 - Target for wp_remote_post
     * This function runs the actual import.
     */
    public function async_background_process() {
        // Nonce is checked in the body of the request
        check_ajax_referer('pip_async_bg_nonce', '_ajax_nonce');
        
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) {
            error_log('Power Importer Pro: Invalid job ID for async background process.');
            wp_send_json_error(['message' => __('Invalid job ID for async process.', 'power-importer-pro')], 400);
            wp_die(); // Important to die to prevent further execution
        }

        // Ensure user has permission, even though it's a server-to-server call, it's good practice
        if (!current_user_can('manage_woocommerce')) {
             pip_db()->add_log($job_id, __('Permission denied for async background process.', 'power-importer-pro'), 'ERROR');
             wp_send_json_error(['message' => __('Permission denied.', 'power-importer-pro')], 403);
             wp_die();
        }

        ignore_user_abort(true); 
        set_time_limit(apply_filters('pip_async_process_time_limit', self::ASYNC_TIMEOUT_SECONDS)); 
        ini_set('memory_limit', self::ASYNC_MEMORY_LIMIT);

        pip_db()->update_job($job_id, ['status' => 'running_async']);
        pip_db()->add_log($job_id, __('Async background process started execution.', 'power-importer-pro'), 'INFO');

        try {
            $this->background_process_job_logic($job_id); 
        } catch (Throwable $e) { // Catch PHP 7+ Errors and Exceptions
            $error_message = sprintf(__( 'Critical error in async background process for job #%d: %s', 'power-importer-pro' ), $job_id, $e->getMessage());
            error_log("Power Importer Pro: " . $error_message); // Server log for deeper debugging
            pip_db()->add_log($job_id, $error_message, 'CRITICAL');
            pip_db()->update_job($job_id, ['status' => 'failed', 'finished_at' => current_time('mysql', 1), 'error_message' => $error_message]);
        }
        
        wp_die(); // Important to terminate the AJAX handler properly
    }
    
    /**
     * 验证CSV文件格式 (AJAX Action)
     */
    public function validate_csv() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'power-importer-pro')]);
            return;
        }
        
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) {
            wp_send_json_error(['message' => __('Invalid job ID.', 'power-importer-pro')]);
            return;
        }
        
        $job = pip_db()->get_job($job_id);
        if (!$job || empty($job->file_path) || !file_exists($job->file_path)) {
            $error_msg = sprintf(__('File not found for job ID %d. Path: %s', 'power-importer-pro'), $job_id, esc_html($job->file_path ?? 'N/A'));
            pip_db()->add_log($job_id, $error_msg, 'ERROR');
            wp_send_json_error(['message' => $error_msg]);
            return;
        }
        
        try {
            $validation_result = $this->validate_csv_file_contents($job->file_path);
            
            pip_db()->update_job($job_id, [
                'total_rows' => $validation_result['total_rows'],
                'status' => 'validated'
            ]);
            pip_db()->add_log($job_id, sprintf(__('CSV validation completed. Total data rows: %d', 'power-importer-pro'), $validation_result['total_rows']), 'INFO');
            
            wp_send_json_success([
                'message' => __('CSV validation successful.', 'power-importer-pro'),
                'data' => $validation_result
            ]);
            
        } catch (Exception $e) {
            pip_db()->add_log($job_id, sprintf(__('CSV validation failed: %s', 'power-importer-pro'), $e->getMessage()), 'ERROR');
            pip_db()->update_job($job_id, ['status' => 'failed', 'error_message' => $e->getMessage()]);
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * 开始批量导入 (AJAX Action for foreground processing)
     */
    public function start_batch_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'power-importer-pro')]);
            return;
        }
        
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) {
            wp_send_json_error(['message' => __('Invalid job ID.', 'power-importer-pro')]);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if (!$job) {
            wp_send_json_error(['message' => __('Job not found.', 'power-importer-pro')]);
            return;
        }

        // Allow starting from 'pending', 'validated', 'paused', or even 'failed'/'cancelled' (implicitly a retry)
        $allowed_statuses = ['pending', 'validated', 'paused', 'failed', 'cancelled', 'uploaded'];
        if (!in_array($job->status, $allowed_statuses)) {
            wp_send_json_error(['message' => sprintf(__('Job cannot be started from current status: %s', 'power-importer-pro'), $job->status)]);
            return;
        }
        
        pip_db()->update_job($job_id, [
            'status' => 'running_ajax', 
            'started_at' => current_time('mysql', 1),
            'processed_rows' => 0, // Reset progress for a new run/retry
            'finished_at' => null, // Clear finished time
            'error_message' => null // Clear previous errors
        ]);
        
        pip_db()->add_log($job_id, __('Foreground AJAX import process started.', 'power-importer-pro'), 'INFO');
        
        wp_send_json_success(['message' => __('Import process started (AJAX mode).', 'power-importer-pro'), 'job_id' => $job_id, 'status' => 'running_ajax']);
    }
    
    /**
     * 处理单个批次 (AJAX Action for foreground processing)
     */
    public function process_batch() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'power-importer-pro')]);
            return;
        }
        
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        $start_row = isset($_POST['start_row']) ? absint($_POST['start_row']) : 0; // This is the count of already processed rows (offset)
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : self::BATCH_SIZE;
        
        if (!$job_id) {
            wp_send_json_error(['message' => __('Invalid job ID.', 'power-importer-pro')]);
            return;
        }
        
        $job = pip_db()->get_job($job_id);
        if (!$job) {
            wp_send_json_error(['message' => __('Job not found.', 'power-importer-pro')]);
            return;
        }

        if ($job->status !== 'running_ajax') {
            pip_db()->add_log($job_id, sprintf(__('Batch processing attempted but job status is %s, not running_ajax.', 'power-importer-pro'), $job->status), 'WARNING');
            wp_send_json_error(['message' => sprintf(__('Job not in a running state for AJAX processing. Current status: %s', 'power-importer-pro'), $this->get_status_label($job->status)), 'status' => $job->status]);
            return;
        }
        
        try {
            $result = $this->process_csv_batch_rows($job, $start_row, $batch_size);
            
            $new_processed_count = $start_row + $result['processed_in_this_batch'];
            pip_db()->update_job($job_id, ['processed_rows' => $new_processed_count]);
            
            $is_complete = ($job->total_rows > 0 && $new_processed_count >= $job->total_rows);
            
            if ($is_complete) {
                pip_db()->update_job($job_id, [
                    'status' => 'completed',
                    'finished_at' => current_time('mysql', 1)
                ]);
                pip_db()->add_log($job_id, __('AJAX Import completed successfully.', 'power-importer-pro'), 'SUCCESS');
            }
            
            wp_send_json_success([
                'processed' => $result['processed_in_this_batch'],
                'errors' => $result['errors_in_this_batch'],
                'total_processed_so_far' => $new_processed_count,
                'is_complete' => $is_complete,
                'next_start_row' => $new_processed_count // The next offset
            ]);
            
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            pip_db()->add_log($job_id, sprintf(__('AJAX Batch processing error: %s', 'power-importer-pro'), $error_msg), 'ERROR');
            pip_db()->update_job($job_id, ['status' => 'failed', 'error_message' => $error_msg, 'finished_at' => current_time('mysql', 1)]);
            wp_send_json_error(['message' => $error_msg]);
        }
    }
    
    /**
     * 暂停导入 (AJAX Action for foreground processing)
     */
    public function pause_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) { wp_send_json_error(['message' => __('Invalid job ID.', 'power-importer-pro')]); return; }

        $job = pip_db()->get_job($job_id);
        if ($job && $job->status === 'running_ajax') {
            pip_db()->update_job($job_id, ['status' => 'paused']);
            pip_db()->add_log($job_id, __('AJAX Import paused by user.', 'power-importer-pro'), 'INFO');
            wp_send_json_success(['message' => __('Import paused.', 'power-importer-pro'), 'new_status' => 'paused']);
        } else {
            wp_send_json_error(['message' => __('Job cannot be paused. Not currently in AJAX running state.', 'power-importer-pro')]);
        }
    }
    
    /**
     * 恢复导入 (AJAX Action for foreground processing)
     */
    public function resume_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) { wp_send_json_error(['message' => __('Invalid job ID.', 'power-importer-pro')]); return; }
        
        $job = pip_db()->get_job($job_id);
        if ($job && $job->status === 'paused') {
            pip_db()->update_job($job_id, ['status' => 'running_ajax']);
            pip_db()->add_log($job_id, __('AJAX Import resumed by user.', 'power-importer-pro'), 'INFO');
            wp_send_json_success(['message' => __('Import resumed.', 'power-importer-pro'), 'new_status' => 'running_ajax']);
        } else {
            wp_send_json_error(['message' => __('Job cannot be resumed. Not currently paused.', 'power-importer-pro')]);
        }
    }
    
    /**
     * 取消导入 (AJAX Action)
     */
    public function cancel_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) { wp_send_json_error(['message' => __('Invalid job ID.', 'power-importer-pro')]); return; }
        
        pip_db()->update_job($job_id, [
            'status' => 'cancelled',
            'finished_at' => current_time('mysql', 1)
        ]);
        pip_db()->add_log($job_id, __('Import cancelled by user.', 'power-importer-pro'), 'INFO');
        wp_send_json_success(['message' => __('Import cancelled.', 'power-importer-pro'), 'new_status' => 'cancelled']);
    }
    
    /**
     * 获取导入状态 (AJAX Action)
     */
    public function get_import_status() {
        check_ajax_referer('pip_ajax_nonce', 'nonce'); 
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        if (!$job_id) { wp_send_json_error(['message' => __('Invalid job ID.', 'power-importer-pro')]); return; }
        
        $job = pip_db()->get_job($job_id);
        if (!$job) { wp_send_json_error(['message' => __('Job not found.', 'power-importer-pro')]); return; }
        
        $progress = ($job->total_rows > 0 && is_numeric($job->processed_rows) && is_numeric($job->total_rows)) 
                    ? round(((int)$job->processed_rows / (int)$job->total_rows) * 100, 2) 
                    : 0;
        
        wp_send_json_success([
            'status' => $job->status,
            'processed_rows' => (int)$job->processed_rows,
            'total_rows' => (int)$job->total_rows,
            'progress' => $progress,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'error_message' => $job->error_message
        ]);
    }
    
    /**
     * 内部方法: 验证CSV文件内容 (reads file and checks headers/rows)
     */
    private function validate_csv_file_contents($file_path) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            throw new Exception(sprintf(__('CSV file not found or not readable at: %s', 'power-importer-pro'), $file_path));
        }
        
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception(__('Cannot open CSV file for validation.', 'power-importer-pro'));
        }
        
        $headers = fgetcsv($handle);
        if ($headers === false || empty($headers)) {
            fclose($handle);
            throw new Exception(__('Invalid CSV format - no headers found or file is empty.', 'power-importer-pro'));
        }
        $headers = array_map('trim', $headers);

        $required_columns = ['Name', 'SKU', 'Type']; 
        $missing_columns = array_diff($required_columns, $headers);
        
        if (!empty($missing_columns)) {
            fclose($handle);
            throw new Exception(sprintf(__('Missing required columns: %s', 'power-importer-pro'), implode(', ', $missing_columns)));
        }
        
        $total_rows = 0;
        while (fgetcsv($handle) !== false) {
            $total_rows++;
        }
        fclose($handle);
        
        return [
            'total_rows' => $total_rows, 
            'headers' => $headers,
            'required_columns' => $required_columns,
            'validation_passed' => true
        ];
    }
    
    /**
     * 内部方法: 处理CSV批次 (for AJAX processing)
     */
    private function process_csv_batch_rows($job, $processed_rows_count, $batch_size) {
        if (!file_exists($job->file_path) || !is_readable($job->file_path)) {
            throw new Exception(sprintf(__('CSV file not found or not readable during batch processing: %s', 'power-importer-pro'), $job->file_path));
        }

        $handle = fopen($job->file_path, 'r');
        if (!$handle) {
            throw new Exception(__('Cannot open CSV file for batch processing.', 'power-importer-pro'));
        }
        
        $csv_headers = fgetcsv($handle); 
        if ($csv_headers === false) {
             fclose($handle);
             throw new Exception(__('Failed to read CSV headers during batch processing.', 'power-importer-pro'));
        }
        $csv_headers = array_map('trim', $csv_headers);

        for ($i = 0; $i < $processed_rows_count; $i++) {
            if (fgetcsv($handle) === false) break; 
        }
        
        $processed_in_this_batch = 0;
        $errors_in_this_batch = [];
        
        if (!class_exists('PIP_Importer')) {
            throw new Exception(__('Importer class PIP_Importer not found.', 'power-importer-pro'));
        }
        $importer = new PIP_Importer($job->file_path, $job->id);
        $importer->set_variable_product_map(maybe_unserialize($job->variable_product_map ?? '')); // Load map for variations

        for ($i = 0; $i < $batch_size; $i++) {
            $data = fgetcsv($handle);
            if ($data === false) break; // End of file
            
            $current_row_number_for_logging = $processed_rows_count + $processed_in_this_batch + 1;

            try {
                if (count($data) === count($csv_headers)) {
                    $product_data = array_combine($csv_headers, $data);
                    $importer->set_row_count($current_row_number_for_logging);
                    $importer->process_single_row($product_data);
                    $processed_in_this_batch++;
                } else {
                    $mismatch_error = sprintf(__( 'Row %d: Column count mismatch. Expected %d, got %d. Skipping row.', 'power-importer-pro' ), $current_row_number_for_logging, count($csv_headers), count($data));
                    $errors_in_this_batch[] = $mismatch_error;
                    pip_db()->add_log($job->id, $mismatch_error, 'WARNING');
                }
            } catch (Exception $e) {
                $row_error = sprintf(__( 'Row %d: Error - %s', 'power-importer-pro' ), $current_row_number_for_logging, $e->getMessage());
                $errors_in_this_batch[] = $row_error;
                pip_db()->add_log($job->id, $row_error, 'ERROR');
            }
        }
        
        fclose($handle);
        // Save variable product map state for next batch
        pip_db()->update_job($job->id, ['variable_product_map' => serialize($importer->get_variable_product_map())]);
        
        return [
            'processed_in_this_batch' => $processed_in_this_batch,
            'errors_in_this_batch' => $errors_in_this_batch
        ];
    }
    
    /**
     * 内部方法: 后台处理整个任务 (called by async_background_process)
     */
    private function background_process_job_logic($job_id) {
        $job = pip_db()->get_job($job_id);
        if (!$job) {
            pip_db()->add_log($job_id, __('Background process: Job not found.', 'power-importer-pro'), 'ERROR');
            return;
        }

        if ($job->status === 'cancelled') {
            pip_db()->add_log($job_id, __('Background process: Job was cancelled. Aborting.', 'power-importer-pro'), 'INFO');
            return;
        }
        
        // Ensure status is running_async
        pip_db()->update_job($job_id, ['status' => 'running_async', 'started_at' => $job->started_at ?? current_time('mysql', 1)]);
        pip_db()->add_log($job_id, __('Background processing job execution started.', 'power-importer-pro'), 'INFO');
        
        try {
            if (!class_exists('PIP_Importer')) {
                throw new Exception(__('Importer class PIP_Importer not found.', 'power-importer-pro'));
            }
            // Load required WordPress files for product creation/modification
            require_once( ABSPATH . 'wp-admin/includes/taxonomy.php' );
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
            require_once( ABSPATH . 'wp-includes/kses.php' );
            
            $importer = new PIP_Importer($job->file_path, $job_id);
            $importer->run(); // This method should handle its own logging and final status updates

            $final_job_status = pip_db()->get_job_field($job_id, 'status');
            if ($final_job_status === 'running_async') { 
                pip_db()->add_log($job_id, __('Background process job: Importer finished but status was not updated. Marking as completed.', 'power-importer-pro'), 'WARNING');
                pip_db()->update_job($job_id, ['status' => 'completed', 'finished_at' => current_time('mysql', 1)]);
            } else {
                 pip_db()->add_log($job_id, sprintf(__('Background process job finished with status: %s.', 'power-importer-pro'), $final_job_status), 'INFO');
            }
            
        } catch (Throwable $e) { // Catch PHP 7+ Errors and Exceptions
            $error_message = sprintf(__( 'Critical error during background processing for job #%d: %s. File: %s, Line: %s', 'power-importer-pro' ), $job_id, $e->getMessage(), $e->getFile(), $e->getLine());
            error_log("Power Importer Pro: " . $error_message); 
            pip_db()->add_log($job_id, $error_message, 'CRITICAL');
            pip_db()->update_job($job_id, [
                'status' => 'failed',
                'finished_at' => current_time('mysql', 1),
                'error_message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Helper to get status label for UI, considering i18n.
     */
    private function get_status_label($status_key) {
        $statuses = [
            'pending' => __('Pending Validation', 'power-importer-pro'),
            'uploaded' => __('Uploaded', 'power-importer-pro'),
            'validated' => __('Validated', 'power-importer-pro'),
            'running_ajax' => __('Running (AJAX)', 'power-importer-pro'),
            'queued_async' => __('Queued (Background)', 'power-importer-pro'),
            'running_async' => __('Running (Background)', 'power-importer-pro'),
            'completed' => __('Completed', 'power-importer-pro'),
            'failed' => __('Failed', 'power-importer-pro'),
            'paused' => __('Paused (AJAX)', 'power-importer-pro'),
            'cancelled' => __('Cancelled', 'power-importer-pro'),
        ];
        return $statuses[$status_key] ?? ucfirst(str_replace('_', ' ', $status_key));
    }
}

// It's better to instantiate the class within the pip_init function or similar
// to ensure all WordPress functions are available.
// new PIP_Ajax_Processor(); // This line might be removed if instantiation is handled in main plugin file.
?>
