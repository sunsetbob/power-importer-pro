<?php
/**
 * AJAX分片处理器类 - 支持多种后台处理方式
 * 实现断点续传和批量处理功能
 */
if (!defined('ABSPATH')) exit;

class PIP_Ajax_Processor {
    
    const BATCH_SIZE = 10; // 每批处理的行数
    const PROCESS_DELAY = 1000; // 批次间延迟(毫秒)
    
    public function __construct() {
        // 注册AJAX钩子
        add_action('wp_ajax_pip_start_batch_import', [$this, 'start_batch_import']);
        add_action('wp_ajax_pip_process_batch', [$this, 'process_batch']);
        add_action('wp_ajax_pip_pause_import', [$this, 'pause_import']);
        add_action('wp_ajax_pip_resume_import', [$this, 'resume_import']);
        add_action('wp_ajax_pip_cancel_import', [$this, 'cancel_import']);
        add_action('wp_ajax_pip_get_import_status', [$this, 'get_import_status']);
        add_action('wp_ajax_pip_validate_csv', [$this, 'validate_csv']);
        
        // 后台处理钩子
        add_action('wp_ajax_pip_enable_background_mode', [$this, 'enable_background_mode']);
        add_action('wp_ajax_pip_async_background_process', [$this, 'async_background_process']);
        add_action('wp_ajax_pip_background_poll', [$this, 'background_poll']);
        add_action('wp_ajax_nopriv_pip_background_poll', [$this, 'background_poll']);
        add_action('pip_background_process_job', [$this, 'background_process_job']);
        
        // 检测wp-cron状态并选择合适的后台处理方式
        add_action('init', [$this, 'init_background_processor']);
    }
    
    /**
     * 初始化后台处理器 - 检测wp-cron状态
     */
    public function init_background_processor() {
        // 检测wp-cron是否可用
        $cron_available = $this->is_wp_cron_available();
        
        if ($cron_available) {
            // 使用WordPress Cron
            $this->schedule_wp_cron_processor();
        } else {
            // 使用替代方案
            $this->schedule_alternative_processor();
        }
    }
    
    /**
     * 检测wp-cron是否可用
     */
    private function is_wp_cron_available() {
        // 检查DISABLE_WP_CRON常量
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return false;
        }
        
        // 检查wp-cron.php是否可访问
        $cron_url = site_url('wp-cron.php');
        $response = wp_remote_get($cron_url, [
            'timeout' => 5,
            'blocking' => true,
            'sslverify' => false
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    /**
     * WordPress Cron处理器
     */
    private function schedule_wp_cron_processor() {
        if (!wp_next_scheduled('pip_check_background_jobs')) {
            wp_schedule_event(time(), 'every_minute', 'pip_check_background_jobs');
        }
        add_action('pip_check_background_jobs', [$this, 'check_background_jobs']);
    }
    
    /**
     * 替代处理器 - 不依赖wp-cron
     */
    private function schedule_alternative_processor() {
        // 使用WordPress Heartbeat API
        add_filter('heartbeat_received', [$this, 'heartbeat_background_processor'], 10, 2);
    }
    
    /**
     * 启用后台处理模式 - 改进版
     */
    public function enable_background_mode() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $job_id = intval($_POST['job_id'] ?? 0);
        $mode = sanitize_text_field($_POST['mode'] ?? 'auto');
        
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }
        
        // 检测最佳后台处理方式
        $cron_available = $this->is_wp_cron_available();
        $selected_mode = $mode === 'auto' ? ($cron_available ? 'cron' : 'immediate') : $mode;
        
        // 更新任务状态
        pip_db()->update_job($job_id, [
            'status' => 'background_processing',
            'started_at' => current_time('mysql', 1)
        ]);
        
        // 根据模式启动后台处理
        switch ($selected_mode) {
            case 'cron':
                wp_schedule_single_event(time() + 10, 'pip_background_process_job', [$job_id]);
                $message = 'Background processing enabled (WordPress Cron mode)';
                break;
                
            case 'immediate':
                // 立即异步处理（不依赖wp-cron）
                $this->immediate_background_process($job_id);
                $message = 'Background processing started (Async mode - wp-cron disabled)';
                break;
                
            default:
                wp_send_json_error(['message' => 'Invalid processing mode']);
                return;
        }
        
        pip_db()->add_log($job_id, "已启用后台处理模式: {$selected_mode}", 'INFO');
        
        wp_send_json_success([
            'message' => $message,
            'mode' => $selected_mode,
            'cron_available' => $cron_available
        ]);
    }
    
    /**
     * 立即后台处理（不依赖wp-cron）
     */
    private function immediate_background_process($job_id) {
        // 使用wp_remote_post异步调用
        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 1,
            'blocking' => false,
            'body' => [
                'action' => 'pip_async_background_process',
                'job_id' => $job_id,
                'nonce' => wp_create_nonce('pip_async_nonce')
            ]
        ]);
    }
    
    /**
     * 异步后台处理
     */
    public function async_background_process() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pip_async_nonce')) {
            wp_die('Security check failed');
        }
        
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_die('Invalid job ID');
        }
        
        // 设置长时间执行
        ignore_user_abort(true);
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        // 执行导入
        $this->background_process_job($job_id);
        
        wp_die(); // 正常结束
    }
    
    /**
     * Heartbeat后台处理器
     */
    public function heartbeat_background_processor($response, $data) {
        if (!isset($data['pip_background_check'])) {
            return $response;
        }
        
        // 查找需要处理的后台任务
        global $wpdb;
        $jobs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}pip_import_jobs 
             WHERE status = 'background_processing' 
             LIMIT 1"
        );
        
        foreach ($jobs as $job) {
            // 处理一个小批次
            $this->process_background_batch($job->id);
        }
        
        $response['pip_background_status'] = 'processed';
        return $response;
    }
    
    /**
     * 处理后台批次
     */
    private function process_background_batch($job_id) {
        $job = pip_db()->get_job($job_id);
        if (!$job || $job->status !== 'background_processing') {
            return false;
        }
        
        try {
            // 处理一个小批次
            $result = $this->process_csv_batch($job, $job->processed_rows, 5);
            
            // 更新进度
            $new_processed = $job->processed_rows + $result['processed'];
            pip_db()->update_job($job_id, ['processed_rows' => $new_processed]);
            
            // 检查是否完成
            if ($new_processed >= $job->total_rows) {
                pip_db()->update_job($job_id, [
                    'status' => 'completed',
                    'finished_at' => current_time('mysql', 1)
                ]);
                
                pip_db()->add_log($job_id, '后台处理完成', 'SUCCESS');
            }
            
            return true;
            
        } catch (Exception $e) {
            pip_db()->add_log($job_id, '后台批次处理错误: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * AJAX轮询处理器
     */
    public function background_poll() {
        // 简单的安全检查
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied');
        }
        
        // 处理所有后台任务
        global $wpdb;
        $jobs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}pip_import_jobs 
             WHERE status = 'background_processing' 
             LIMIT 3"
        );
        
        $processed = 0;
        foreach ($jobs as $job) {
            if ($this->process_background_batch($job->id)) {
                $processed++;
            }
        }
        
        wp_send_json_success(['processed_jobs' => $processed]);
    }
    
    /**
     * 验证CSV文件格式
     */
    public function validate_csv() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }
        
        $job = pip_db()->get_job($job_id);
        if (!$job || !file_exists($job->file_path)) {
            wp_send_json_error(['message' => 'File not found']);
        }
        
        try {
            $validation_result = $this->validate_csv_file($job->file_path);
            
            // 更新任务总行数
            pip_db()->update_job($job_id, [
                'total_rows' => $validation_result['total_rows'],
                'status' => 'validated'
            ]);
            
            wp_send_json_success([
                'message' => 'CSV validation completed',
                'data' => $validation_result
            ]);
            
        } catch (Exception $e) {
            pip_db()->add_log($job_id, 'CSV validation failed: ' . $e->getMessage(), 'ERROR');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * 开始批量导入
     */
    public function start_batch_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }
        
        // 更新任务状态为运行中
        pip_db()->update_job($job_id, [
            'status' => 'running',
            'started_at' => current_time('mysql', 1)
        ]);
        
        pip_db()->add_log($job_id, 'Batch import started', 'INFO');
        
        wp_send_json_success(['message' => 'Import started']);
    }
    
    /**
     * 处理单个批次
     */
    public function process_batch() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $job_id = intval($_POST['job_id'] ?? 0);
        $start_row = intval($_POST['start_row'] ?? 0);
        $batch_size = intval($_POST['batch_size'] ?? self::BATCH_SIZE);
        
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }
        
        $job = pip_db()->get_job($job_id);
        if (!$job || $job->status !== 'running') {
            wp_send_json_error(['message' => 'Job not ready for processing']);
        }
        
        try {
            $result = $this->process_csv_batch($job, $start_row, $batch_size);
            
            // 更新进度
            $new_processed = $start_row + $result['processed'];
            pip_db()->update_job($job_id, ['processed_rows' => $new_processed]);
            
            // 检查是否完成
            if ($new_processed >= $job->total_rows) {
                pip_db()->update_job($job_id, [
                    'status' => 'completed',
                    'finished_at' => current_time('mysql', 1)
                ]);
                pip_db()->add_log($job_id, 'Import completed successfully', 'SUCCESS');
            }
            
            wp_send_json_success([
                'processed' => $result['processed'],
                'errors' => $result['errors'],
                'total_processed' => $new_processed,
                'is_complete' => $new_processed >= $job->total_rows,
                'next_start_row' => $start_row + $result['processed']
            ]);
            
        } catch (Exception $e) {
            pip_db()->add_log($job_id, 'Batch processing error: ' . $e->getMessage(), 'ERROR');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * 暂停导入
     */
    public function pause_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }
        
        pip_db()->update_job($job_id, ['status' => 'paused']);
        pip_db()->add_log($job_id, 'Import paused by user', 'INFO');
        
        wp_send_json_success(['message' => 'Import paused']);
    }
    
    /**
     * 恢复导入
     */
    public function resume_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }
        
        pip_db()->update_job($job_id, ['status' => 'running']);
        pip_db()->add_log($job_id, 'Import resumed by user', 'INFO');
        
        wp_send_json_success(['message' => 'Import resumed']);
    }
    
    /**
     * 取消导入
     */
    public function cancel_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }
        
        pip_db()->update_job($job_id, [
            'status' => 'cancelled',
            'finished_at' => current_time('mysql', 1)
        ]);
        pip_db()->add_log($job_id, 'Import cancelled by user', 'INFO');
        
        wp_send_json_success(['message' => 'Import cancelled']);
    }
    
    /**
     * 获取导入状态
     */
    public function get_import_status() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }
        
        $job = pip_db()->get_job($job_id);
        if (!$job) {
            wp_send_json_error(['message' => 'Job not found']);
        }
        
        $progress = $job->total_rows > 0 ? round(($job->processed_rows / $job->total_rows) * 100, 2) : 0;
        
        wp_send_json_success([
            'status' => $job->status,
            'processed_rows' => $job->processed_rows,
            'total_rows' => $job->total_rows,
            'progress' => $progress,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at
        ]);
    }
    
    /**
     * 验证CSV文件
     */
    private function validate_csv_file($file_path) {
        if (!file_exists($file_path)) {
            throw new Exception('CSV file not found');
        }
        
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception('Cannot open CSV file');
        }
        
        // 读取标题行
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new Exception('Invalid CSV format - no headers found');
        }
        
        // 验证必需的列
        $required_columns = ['Name', 'SKU', 'Type'];
        $missing_columns = array_diff($required_columns, $headers);
        
        if (!empty($missing_columns)) {
            fclose($handle);
            throw new Exception('Missing required columns: ' . implode(', ', $missing_columns));
        }
        
        // 计算总行数
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
     * 处理CSV批次
     */
    private function process_csv_batch($job, $start_row, $batch_size) {
        $handle = fopen($job->file_path, 'r');
        if (!$handle) {
            throw new Exception('Cannot open CSV file');
        }
        
        // 读取标题行
        $headers = fgetcsv($handle);
        
        // 跳过到起始行
        for ($i = 0; $i < $start_row; $i++) {
            if (fgetcsv($handle) === false) {
                break;
            }
        }
        
        $processed = 0;
        $errors = [];
        
        // 创建导入器实例
        $importer = new PIP_Importer($job->file_path, $job->id);
        
        // 处理批次
        for ($i = 0; $i < $batch_size; $i++) {
            $data = fgetcsv($handle);
            if ($data === false) {
                break; // 文件结束
            }
            
            try {
                if (count($data) === count($headers)) {
                    $product_data = array_combine($headers, $data);
                    // 这里需要修改PIP_Importer类，添加单行处理方法
                    $this->process_single_row($importer, $product_data, $start_row + $i + 1);
                    $processed++;
                } else {
                    $errors[] = "Row " . ($start_row + $i + 1) . ": Column count mismatch";
                }
            } catch (Exception $e) {
                $errors[] = "Row " . ($start_row + $i + 1) . ": " . $e->getMessage();
                pip_db()->add_log($job->id, "Row error: " . $e->getMessage(), 'WARNING');
            }
        }
        
        fclose($handle);
        
        return [
            'processed' => $processed,
            'errors' => $errors
        ];
    }
    
    /**
     * 处理单行数据
     */
    private function process_single_row($importer, $product_data, $row_number) {
        // 使用新的公共方法
        $importer->set_row_count($row_number);
        $importer->process_single_row($product_data);
    }
    
    /**
     * 后台处理任务
     */
    public function background_process_job($job_id) {
        $job = pip_db()->get_job($job_id);
        if (!$job || !in_array($job->status, ['background_processing', 'running'])) {
            return;
        }
        
        try {
            // 设置更长的执行时间限制
            set_time_limit(300); // 5分钟
            ini_set('memory_limit', '512M');
            
            pip_db()->add_log($job_id, '后台处理开始执行', 'INFO');
            
            // 创建导入器实例并运行
            $importer = new PIP_Importer($job->file_path, $job_id);
            $importer->run();
            
            pip_db()->add_log($job_id, '后台处理完成', 'SUCCESS');
            
        } catch (Exception $e) {
            pip_db()->add_log($job_id, '后台处理错误: ' . $e->getMessage(), 'ERROR');
            pip_db()->update_job($job_id, [
                'status' => 'failed',
                'finished_at' => current_time('mysql', 1)
            ]);
        }
    }
    
    /**
     * 检查后台任务
     */
    public function check_background_jobs() {
        global $wpdb;
        
        // 查找需要后台处理的任务
        $jobs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}pip_import_jobs 
             WHERE status = 'background_processing' 
             AND (started_at IS NULL OR started_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE))
             LIMIT 3"
        );
        
        foreach ($jobs as $job) {
            // 避免重复调度
            if (!wp_next_scheduled('pip_background_process_job', [$job->id])) {
                wp_schedule_single_event(time() + 5, 'pip_background_process_job', [$job->id]);
                pip_db()->add_log($job->id, '后台任务已调度', 'INFO');
            }
        }
    }
}

// 初始化AJAX处理器
new PIP_Ajax_Processor(); 