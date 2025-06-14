<?php
/**
 * AJAX分片处理器类 - Refactored for wp_remote_post async processing only.
 * Implements chunked import and batch processing.
 */
if (!defined('ABSPATH')) exit;

class PIP_Ajax_Processor {

    const BATCH_SIZE = 10; // Number of rows to process per batch in AJAX mode
    const PROCESS_DELAY = 1000; // Delay between AJAX batches in milliseconds

    public function __construct() {
        // Register AJAX hooks for frontend interactions
        add_action('wp_ajax_pip_start_batch_import', [$this, 'start_batch_import']);
        add_action('wp_ajax_pip_process_batch', [$this, 'process_batch']);
        add_action('wp_ajax_pip_pause_import', [$this, 'pause_import']);
        add_action('wp_ajax_pip_resume_import', [$this, 'resume_import']);
        add_action('wp_ajax_pip_cancel_import', [$this, 'cancel_import']);
        add_action('wp_ajax_pip_get_import_status', [$this, 'get_import_status']);
        add_action('wp_ajax_pip_validate_csv', [$this, 'validate_csv']);

        // Hooks for background processing via wp_remote_post
        add_action('wp_ajax_pip_enable_background_mode', [$this, 'enable_background_mode']);
        add_action('wp_ajax_nopriv_pip_async_background_process', [$this, 'async_background_process']); // Nopriv for loopback
        add_action('wp_ajax_pip_async_background_process', [$this, 'async_background_process']);
    }

    /**
     * Validates CSV file format and structure.
     */
    public function validate_csv() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID.']);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if (!$job || empty($job->file_path) || !file_exists($job->file_path)) {
            wp_send_json_error(['message' => 'File not found for the job.']);
            return;
        }

        try {
            $validation_result = $this->validate_csv_file($job->file_path);

            pip_db()->update_job($job_id, [
                'total_rows' => $validation_result['total_rows'],
                'status' => 'validated' // Status indicating CSV is valid and ready for import
            ]);

            pip_db()->add_log($job_id, 'CSV validation completed successfully.', 'INFO');
            wp_send_json_success([
                'message' => 'CSV validation completed.',
                'data' => $validation_result
            ]);

        } catch (Exception $e) {
            pip_db()->add_log($job_id, 'CSV validation failed: ' . $e->getMessage(), 'ERROR');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Starts the AJAX-driven batch import process.
     */
    public function start_batch_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID.']);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if (!$job || !in_array($job->status, ['validated', 'paused'])) {
            $status = $job ? $job->status : 'unknown';
            wp_send_json_error(['message' => "Cannot start import. Job status is '{$status}'. Expected 'validated' or 'paused'."]);
            return;
        }

        pip_db()->update_job($job_id, [
            'status' => 'running_ajax', // Status for browser-driven AJAX processing
            'started_at' => current_time('mysql', 1)
        ]);

        pip_db()->add_log($job_id, 'AJAX batch import started.', 'INFO');
        wp_send_json_success(['message' => 'Import started via AJAX processing.']);
    }

    /**
     * Processes a single batch of CSV data during AJAX import.
     */
    public function process_batch() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        $job_id = intval($_POST['job_id'] ?? 0);
        $batch_size = intval($_POST['batch_size'] ?? self::BATCH_SIZE);

        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID.']);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if (!$job || $job->status !== 'running_ajax') {
            $status = $job ? $job->status : 'unknown';
            wp_send_json_error(['message' => "Job not ready for AJAX batch processing (status: {$status})."]);
            return;
        }

        try {
            // $job->processed_rows is the authoritative starting point for this batch
            $result = $this->process_csv_batch($job, $job->processed_rows, $batch_size);

            $new_total_processed = $job->processed_rows + $result['processed'];
            pip_db()->update_job($job_id, ['processed_rows' => $new_total_processed]);

            $is_complete = ($new_total_processed >= $job->total_rows);
            if ($is_complete) {
                pip_db()->update_job($job_id, [
                    'status' => 'completed',
                    'finished_at' => current_time('mysql', 1)
                ]);
                pip_db()->add_log($job_id, 'AJAX import completed successfully.', 'SUCCESS');
            }

            wp_send_json_success([
                'processed' => $result['processed'],
                'errors' => $result['errors'],
                'total_processed' => $new_total_processed,
                'is_complete' => $is_complete,
                'next_start_row' => $new_total_processed
            ]);

        } catch (Exception $e) {
            pip_db()->add_log($job_id, 'AJAX batch processing error: ' . $e->getMessage(), 'ERROR');
            pip_db()->update_job($job_id, ['status' => 'failed', 'error_message' => $e->getMessage()]);
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Enables background processing mode (now only uses async wp_remote_post).
     */
    public function enable_background_mode() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            pip_db()->add_log(0, 'Enable background mode: Permission denied.', 'ERROR');
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            pip_db()->add_log(0, 'Enable background mode: Invalid job ID provided.', 'ERROR');
            wp_send_json_error(['message' => 'Invalid job ID.']);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if (!$job) {
            pip_db()->add_log($job_id, 'Enable background mode: Job not found.', 'ERROR');
            wp_send_json_error(['message' => 'Job not found.']);
            return;
        }

        // Prevent re-queueing if already processing or finished
        $non_requeueable_statuses = ['queued_async', 'running_async', 'completed', 'failed', 'cancelled'];
        if (in_array($job->status, $non_requeueable_statuses)) {
            pip_db()->add_log($job_id, "Enable background mode: Job status '{$job->status}' prevents re-queueing.", 'WARNING');
            wp_send_json_error(['message' => "Job is already processing, scheduled, or finished ({$job->status})."]);
            return;
        }

        // Directly attempt to trigger async background process
        $trigger_success = $this->immediate_background_process($job_id);

        if ($trigger_success) {
            // Status is set to 'queued_async' by immediate_background_process
            pip_db()->add_log($job_id, 'Background processing initiated via Async remote post.', 'INFO');
            wp_send_json_success([
                'message' => 'Background processing initiated.',
                'mode' => 'async', // Explicitly state the mode
                'status' => 'queued_async'
            ]);
        } else {
            // Error logged and status set to 'failed' by immediate_background_process
            wp_send_json_error([
                'message' => 'Failed to initiate background processing. Check plugin logs.',
                'mode' => 'async',
                'status' => 'failed'
            ]);
        }
    }

    /**
     * Initiates the non-blocking wp_remote_post call for async background processing.
     * Updates job status to 'queued_async' or 'failed'.
     * Returns true on successful trigger attempt, false on failure.
     */
    private function immediate_background_process($job_id) {
        pip_db()->update_job($job_id, ['status' => 'queued_async']);
        pip_db()->add_log($job_id, 'Async process: Attempting to trigger. Status set to queued_async.', 'INFO');

        $nonce = wp_create_nonce('pip_async_bg_nonce');
        $url = admin_url('admin-ajax.php');
        $args = [
            'timeout'   => 1, // Very short, effectively non-blocking
            'blocking'  => false, // Crucial for non-blocking behavior
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body'      => [
                'action'   => 'pip_async_background_process',
                'job_id'   => $job_id,
                '_ajax_nonce' => $nonce // Pass nonce in body for check_ajax_referer
            ]
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            pip_db()->add_log($job_id, "Async process trigger failed: WP_Error - {$error_message}", 'ERROR');
            pip_db()->update_job($job_id, ['status' => 'failed', 'error_message' => "Failed to trigger async process: {$error_message}"]);
            return false;
        }

        // For non-blocking requests, a 200 response isn't guaranteed.
        // The main thing is that the request was made without a wp_error.
        $response_code = wp_remote_retrieve_response_code($response);
        pip_db()->add_log($job_id, "Async process successfully triggered via wp_remote_post (HTTP response code: {$response_code}).", 'INFO');
        return true;
    }

    /**
     * Handles the actual background processing logic, called by wp_remote_post.
     */
    public function async_background_process() {
        $job_id = intval($_POST['job_id'] ?? 0);

        // Use check_ajax_referer for nonce validation from POST body
        check_ajax_referer('pip_async_bg_nonce', '_ajax_nonce');
        // If nonce fails, check_ajax_referer will wp_die(), so no need for further checks here.

        if (!$job_id) {
            pip_db()->add_log(0, 'Async process: Invalid or missing job ID after nonce check.', 'ERROR');
            wp_send_json_error(['message' => 'Invalid job ID.'], 400); // Should not happen if nonce is good
            wp_die();
        }

        ignore_user_abort(true);
        set_time_limit(apply_filters('pip_background_process_time_limit', 300)); // 5 minutes, filterable
        ini_set('memory_limit', apply_filters('pip_background_process_memory_limit', '512M'));

        pip_db()->update_job($job_id, ['status' => 'running_async', 'started_at' => current_time('mysql', 1)]);
        pip_db()->add_log($job_id, 'Async background process started execution.', 'INFO');

        try {
            $this->background_process_job($job_id);
            // background_process_job handles its own completion/failure status updates.
        } catch (Throwable $e) { // Catch PHP 7+ Errors and Exceptions
            $error_message = $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
            pip_db()->add_log($job_id, "Async background process critical error: {$error_message}", 'CRITICAL');
            pip_db()->update_job($job_id, [
                'status' => 'failed',
                'finished_at' => current_time('mysql', 1),
                'error_message' => "Critical Error: {$error_message}"
            ]);
        }

        // End the process cleanly. If background_process_job completed, it would have set status.
        // If it failed catastrophically and didn't update DB, job might remain 'running_async'.
        // A separate sweeper mechanism might be needed for such stuck jobs in a production plugin.
        wp_die('PIP Async Process Completed.');
    }

    /**
     * Core background processing task for a job.
     * Called by async_background_process.
     */
    public function background_process_job($job_id) {
        $job = pip_db()->get_job($job_id);

        if (!$job) {
            pip_db()->add_log($job_id, 'Background process job: Job not found.', 'ERROR');
            return;
        }

        if ($job->status === 'cancelled') {
            pip_db()->add_log($job_id, 'Background process job: Job was cancelled. Aborting.', 'INFO');
            return;
        }

        // Double check status, should be 'running_async'
        if ($job->status !== 'running_async') {
             pip_db()->add_log($job_id, "Background process job: Expected status 'running_async', got '{$job->status}'. Proceeding cautiously.", 'WARNING');
        }

        // Time limits should be set by the caller (async_background_process)
        // set_time_limit(apply_filters('pip_background_job_time_limit', 300));
        // ini_set('memory_limit', apply_filters('pip_background_job_memory_limit', '512M'));

        pip_db()->add_log($job_id, 'Background process job: Starting full import.', 'INFO');

        try {
            if (!class_exists('PIP_Importer')) {
                throw new Exception('PIP_Importer class not found.');
            }
            $importer = new PIP_Importer($job->file_path, $job_id);
            // Importer->run() should handle the entire import process,
            // update processed_rows, and set status to 'completed' or 'failed'.
            $importer->run();

            // Check final status set by importer
            $final_job_status = pip_db()->get_job_field($job_id, 'status');
            if ($final_job_status === 'running_async') {
                // Importer finished but didn't set a final status. This is a fallback.
                pip_db()->add_log($job_id, "Background process job: Importer finished but status still 'running_async'. Marking as 'failed' to avoid stuck jobs.", 'WARNING');
                pip_db()->update_job($job_id, [
                    'status' => 'failed',
                    'finished_at' => current_time('mysql', 1),
                    'error_message' => 'Import process completed without explicit success status.'
                ]);
            } elseif ($final_job_status === 'completed') {
                pip_db()->add_log($job_id, 'Background process job: Import completed successfully by importer.', 'SUCCESS');
            } else {
                 pip_db()->add_log($job_id, "Background process job: Import finished by importer. Final status: {$final_job_status}", 'INFO');
            }

        } catch (Throwable $e) { // Catch PHP 7+ Errors and Exceptions
            $error_message = $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
            pip_db()->add_log($job_id, "Background process job error: {$error_message}", 'ERROR');
            pip_db()->update_job($job_id, [
                'status' => 'failed',
                'finished_at' => current_time('mysql', 1),
                'error_message' => $error_message
            ]);
        }
    }

    /**
     * Pauses an AJAX-driven import.
     */
    public function pause_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID.']);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if ($job && $job->status === 'running_ajax') {
            pip_db()->update_job($job_id, ['status' => 'paused']);
            pip_db()->add_log($job_id, 'AJAX import paused by user.', 'INFO');
            wp_send_json_success(['message' => 'Import paused.']);
        } else {
            $status = $job ? $job->status : 'unknown';
            pip_db()->add_log($job_id, "Attempt to pause non-AJAX running job. Status: {$status}", 'WARNING');
            wp_send_json_error(['message' => "Import cannot be paused (current status: {$status})."]);
        }
    }

    /**
     * Resumes a paused AJAX-driven import.
     */
    public function resume_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID.']);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if ($job && $job->status === 'paused') {
            pip_db()->update_job($job_id, ['status' => 'running_ajax']);
            pip_db()->add_log($job_id, 'AJAX import resumed by user.', 'INFO');
            wp_send_json_success(['message' => 'Import resumed.']);
        } else {
            $status = $job ? $job->status : 'unknown';
            pip_db()->add_log($job_id, "Attempt to resume non-paused job. Status: {$status}", 'WARNING');
            wp_send_json_error(['message' => "Import cannot be resumed (current status: {$status})."]);
        }
    }

    /**
     * Cancels an import job.
     */
    public function cancel_import() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID.']);
            return;
        }

        $job = pip_db()->get_job($job_id);
        // Define statuses from which a job can be cancelled
        $cancellable_statuses = ['pending', 'validated', 'running_ajax', 'paused', 'queued_async', 'running_async'];

        if ($job && in_array($job->status, $cancellable_statuses)) {
            $original_status = $job->status;
            pip_db()->update_job($job_id, [
                'status' => 'cancelled',
                'finished_at' => current_time('mysql', 1),
                'error_message' => 'Import cancelled by user.'
            ]);
            pip_db()->add_log($job_id, "Import cancelled by user. Original status: {$original_status}.", 'INFO');
            wp_send_json_success(['message' => 'Import cancelled.']);
        } else {
            $status = $job ? $job->status : 'unknown';
            pip_db()->add_log($job_id, "Attempt to cancel job in invalid state. Status: {$status}", 'WARNING');
            wp_send_json_error(['message' => "Import cannot be cancelled at this stage (current status: {$status})."]);
        }
    }

    /**
     * Gets the current status of an import job.
     */
    public function get_import_status() {
        check_ajax_referer('pip_ajax_nonce', 'nonce');
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID.']);
            return;
        }

        $job = pip_db()->get_job($job_id);
        if (!$job) {
            wp_send_json_error(['message' => 'Job not found.']);
            return;
        }

        $progress = ($job->total_rows > 0) ? round(($job->processed_rows / $job->total_rows) * 100, 2) : 0;

        wp_send_json_success([
            'status' => $job->status,
            'processed_rows' => $job->processed_rows,
            'total_rows' => $job->total_rows,
            'progress' => $progress,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'error_message' => $job->error_message ?? ''
        ]);
    }

    /**
     * Helper function to validate CSV file structure.
     */
    private function validate_csv_file($file_path) {
        if (!file_exists($file_path)) {
            throw new Exception('CSV file not found at path: ' . htmlspecialchars($file_path));
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception('Cannot open CSV file for validation.');
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new Exception('Invalid CSV format: No headers found.');
        }

        // Define required columns (customize as needed)
        $required_columns = ['Name', 'SKU', 'Type'];
        $missing_columns = array_diff($required_columns, $headers);

        if (!empty($missing_columns)) {
            fclose($handle);
            throw new Exception('Missing required columns: ' . implode(', ', $missing_columns));
        }

        $total_rows = 0;
        while (fgetcsv($handle) !== false) {
            $total_rows++;
        }
        fclose($handle);

        return [
            'total_rows' => $total_rows, // Number of data rows (excluding headers)
            'headers' => $headers,
            'file_path' => $file_path // For reference
        ];
    }

    /**
     * Helper function to process a batch of CSV rows.
     * Used by AJAX process_batch.
     */
    private function process_csv_batch($job, $start_row, $batch_size) {
        $handle = fopen($job->file_path, 'r');
        if (!$handle) {
            throw new Exception('Cannot open CSV file for batch processing.');
        }

        $headers = fgetcsv($handle); // Read header row

        // Skip rows up to the starting point of this batch
        for ($i = 0; $i < $start_row; $i++) {
            if (fgetcsv($handle) === false) { // Should not happen if start_row is correct
                break;
            }
        }

        $processed_in_batch = 0;
        $errors_in_batch = [];

        // Assuming PIP_Importer class exists and is loaded.
        if (!class_exists('PIP_Importer')) {
             throw new Exception('PIP_Importer class not found during batch processing.');
        }
        $importer = new PIP_Importer($job->file_path, $job->id);

        for ($i = 0; $i < $batch_size; $i++) {
            $data_row = fgetcsv($handle);
            if ($data_row === false) { // End of file
                break;
            }

            $current_row_number = $start_row + $processed_in_batch + 1; // 1-based row number
            try {
                if (count($data_row) === count($headers)) {
                    $product_data = array_combine($headers, $data_row);
                    // This assumes PIP_Importer has a method to process a single row of data.
                    // You might need to adapt this part based on PIP_Importer's capabilities.
                    // For example, $importer->process_single_item($product_data, $current_row_number);
                    $this->process_single_row($importer, $product_data, $current_row_number); // Using existing helper
                    $processed_in_batch++;
                } else {
                    $errors_in_batch[] = "Row {$current_row_number}: Column count mismatch.";
                    pip_db()->add_log($job->id, "Row {$current_row_number}: Column count mismatch.", 'WARNING');
                }
            } catch (Exception $e) {
                $errors_in_batch[] = "Row {$current_row_number}: " . $e->getMessage();
                pip_db()->add_log($job->id, "Row {$current_row_number} error: " . $e->getMessage(), 'WARNING');
            }
        }

        fclose($handle);

        return [
            'processed' => $processed_in_batch,
            'errors' => $errors_in_batch
        ];
    }

    /**
     * Helper to process a single row using the importer instance.
     * This was an existing method, ensure PIP_Importer supports these calls.
     */
    private function process_single_row($importer, $product_data, $row_number) {
        // These methods need to exist on the PIP_Importer class
        $importer->set_row_count($row_number);
        $importer->process_single_row($product_data);
    }
}

// Initialize the AJAX Processor
new PIP_Ajax_Processor();
?>
