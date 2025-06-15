<?php
/**
 * Plugin Name:       Power Importer Pro
 * Plugin URI:        https://yourwebsite.com/
 * Description:       A professional tool to import WooCommerce products from a CSV file with background processing and detailed logging.
 * Version:           1.5.3
 * Author:            Your Name
 * Author URI:        https://yourwebsite.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       power-importer-pro
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
if ( ! defined( 'PIP_VERSION' ) ) {
    define( 'PIP_VERSION', '1.5.3' ); // Updated version
}
if ( ! defined( 'PIP_PLUGIN_DIR' ) ) {
    define( 'PIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PIP_PLUGIN_URL' ) ) { 
    define( 'PIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'PIP_UPLOAD_DIR_NAME' ) ) {
    define( 'PIP_UPLOAD_DIR_NAME', 'power-importer-pro-files' );
}

/**
 * Load plugin files.
 */
require_once PIP_PLUGIN_DIR . 'includes/class-pip-db.php';
require_once PIP_PLUGIN_DIR . 'includes/class-pip-importer.php';
require_once PIP_PLUGIN_DIR . 'includes/class-pip-ajax-handler.php';
require_once PIP_PLUGIN_DIR . 'includes/class-pip-admin-page.php';
require_once PIP_PLUGIN_DIR . 'includes/pip-functions.php';


/**
 * Initialize the plugin.
 */
function pip_init_plugin() {
    // Load plugin text domain for translations
    load_plugin_textdomain( 'power-importer-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    // Initialize database handler
    PIP_Database::instance();

    // Initialize admin page and AJAX handlers if in admin area
    if ( is_admin() ) {
        new PIP_Admin_Page();
        new PIP_Ajax_Handler(); 
    }
    
    add_action( 'wp_ajax_pip_upload_files', 'pip_ajax_upload_files_callback' );
    add_action( 'wp_ajax_pip_scan_files', 'pip_ajax_scan_files_callback' );
    add_action( 'wp_ajax_pip_reset_job', 'pip_ajax_reset_job_callback' );
    add_action( 'wp_ajax_pip_get_jobs_table', 'pip_ajax_get_jobs_table_callback' );
    add_action( 'wp_ajax_pip_job_action', 'pip_ajax_handle_job_action_callback' );
    add_action( 'wp_ajax_pip_clear_jobs', 'pip_ajax_handle_clear_jobs_callback' );
}
add_action( 'plugins_loaded', 'pip_init_plugin' );


/**
 * Handles file uploads via AJAX.
 */
function pip_ajax_upload_files_callback() {
    check_ajax_referer('pip_upload_nonce', 'security'); 

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'power-importer-pro')]);
        return;
    }
    
    if (empty($_FILES['import_csv_files'])) {
        wp_send_json_error(['message' => __('No files were uploaded.', 'power-importer-pro')]);
        return;
    }
    
    $upload_dir_info = wp_upload_dir();
    $pip_upload_path = trailingslashit($upload_dir_info['basedir']) . PIP_UPLOAD_DIR_NAME;

    if (!file_exists($pip_upload_path)) {
        if (!wp_mkdir_p($pip_upload_path)) {
            wp_send_json_error(['message' => sprintf(__('Upload directory %s could not be created. Please check permissions.', 'power-importer-pro'), esc_html($pip_upload_path))]);
            return;
        }
    }

    if (!is_writable($pip_upload_path)) {
        wp_send_json_error(['message' => sprintf(__('Upload directory %s is not writable.', 'power-importer-pro'), esc_html($pip_upload_path))]);
        return;
    }
    
    $files = $_FILES['import_csv_files'];
    $uploaded_jobs = [];
    $errors = [];
    $allowed_mime_types = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'];

    $normalized_files = [];
    if (is_array($files['name'])) {
        foreach ($files['name'] as $key => $name) {
            if ($files['error'][$key] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $normalized_files[] = [
                'name' => $name,
                'type' => $files['type'][$key],
                'tmp_name' => $files['tmp_name'][$key],
                'error' => $files['error'][$key],
                'size' => $files['size'][$key]
            ];
        }
    } elseif (isset($files['name']) && $files['error'] !== UPLOAD_ERR_NO_FILE) {
        $normalized_files[] = $files;
    }

    if (empty($normalized_files)) {
        wp_send_json_error(['message' => __('No files selected for upload.', 'power-importer-pro')]);
        return;
    }

    foreach ($normalized_files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = sprintf(__('File %1$s: Upload error (code: %2$d). %3$s', 'power-importer-pro'), esc_html($file['name']), $file['error'], pip_get_upload_error_message($file['error']));
            continue;
        }
        
        if (!in_array(strtolower($file['type']), $allowed_mime_types) && !preg_match('/\.(csv|txt)$/i', $file['name'])) {
            $errors[] = sprintf(__('File %1$s: Invalid file type (%2$s). Allowed types: CSV, TXT.', 'power-importer-pro'), esc_html($file['name']), esc_html($file['type']));
            continue;
        }

        $original_filename = sanitize_file_name($file['name']);
        $new_filename = wp_unique_filename($pip_upload_path, $original_filename);
        $filepath = $pip_upload_path . '/' . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            if (!is_valid_csv_file($filepath)) { // Validate after moving
                $errors[] = sprintf(__( 'File %s: Invalid CSV format or missing required headers (Name, SKU, Type). File was uploaded but not added as a job.', 'power-importer-pro'), esc_html($original_filename));
                unlink($filepath); // Remove invalid file
                continue;
            }
            $job_id = PIP_Database::instance()->create_job(basename($filepath), $filepath);
            if ($job_id) {
                $uploaded_jobs[] = [
                    'id' => $job_id,
                    'filename' => $original_filename, 
                    'status' => 'uploaded' 
                ];
                PIP_Database::instance()->add_log($job_id, sprintf(__('File %s uploaded successfully as %s.', 'power-importer-pro'), esc_html($original_filename), esc_html(basename($filepath))), 'INFO');
            } else {
                $errors[] = sprintf(__('File %s: Database error while creating job record.', 'power-importer-pro'), esc_html($original_filename));
                unlink($filepath); 
            }
        } else {
            $errors[] = sprintf(__('File %s: Could not be moved to the uploads directory.', 'power-importer-pro'), esc_html($original_filename));
        }
    }
    
    if (!empty($uploaded_jobs)) {
        $message = sprintf( _n( '%d file uploaded successfully.', '%d files uploaded successfully.', count($uploaded_jobs), 'power-importer-pro' ), count($uploaded_jobs) );
        if (!empty($errors)) {
            $error_message_part = sprintf( _n( ' However, %d file had an error.', ' However, %d files had errors.', count($errors), 'power-importer-pro' ), count($errors) );
            $message .= $error_message_part;
        }
        wp_send_json_success([
            'message' => $message,
            'jobs' => $uploaded_jobs,
            'errors' => $errors 
        ]);
    } else {
        wp_send_json_error([
            'message' => __('No files were uploaded successfully. See errors below.', 'power-importer-pro'),
            'errors' => $errors
        ]);
    }
}


/**
 * Scans the upload directory for CSV/TXT files not yet in the database.
 */
function pip_ajax_scan_files_callback() {
    check_ajax_referer('pip_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'power-importer-pro')]);
        return;
    }
    
    $upload_dir_info = wp_upload_dir();
    $pip_upload_path = trailingslashit($upload_dir_info['basedir']) . PIP_UPLOAD_DIR_NAME;
    
    if (!file_exists($pip_upload_path)) {
        wp_mkdir_p($pip_upload_path); 
        if (!file_exists($pip_upload_path)) {
            wp_send_json_error(['message' => sprintf(__('Upload directory %s does not exist and could not be created.', 'power-importer-pro'), esc_html($pip_upload_path))]);
            return;
        }
    }
    
    $files_in_dir = glob($pip_upload_path . '/*.{csv,CSV,txt,TXT}', GLOB_BRACE);
    if ($files_in_dir === false) { 
        $files_in_dir = [];
    }

    $new_jobs_data = [];
    $existing_files_names = [];
    $error_messages = [];
    
    $existing_db_paths = PIP_Database::instance()->get_all_job_filepaths();
    
    foreach ($files_in_dir as $filepath) {
        $filename = basename($filepath);
        
        if (in_array($filepath, $existing_db_paths)) {
            $existing_files_names[] = $filename;
            continue;
        }
        
        if (!is_valid_csv_file($filepath)) { 
            $error_messages[] = sprintf(__( 'File %s: Invalid CSV format or missing required headers (Name, SKU, Type). Skipped.', 'power-importer-pro'), esc_html($filename));
            continue;
        }
        
        $job_id = PIP_Database::instance()->create_job($filename, $filepath);
        if ($job_id) {
            $new_jobs_data[] = [
                'id' => $job_id,
                'filename' => $filename,
                'status' => 'pending' 
            ];
            PIP_Database::instance()->add_log($job_id, sprintf(__('File %s found via scan and added as a new job.', 'power-importer-pro'), esc_html($filename)), 'INFO');
        } else {
            $error_messages[] = sprintf(__( 'File %s: Database error while creating job record.', 'power-importer-pro'), esc_html($filename));
        }
    }
    
    $message_parts = [];
    if (!empty($new_jobs_data)) {
        $message_parts[] = sprintf( _n( '%d new file added as a job.', '%d new files added as jobs.', count($new_jobs_data), 'power-importer-pro' ), count($new_jobs_data) );
    }
    if (!empty($existing_files_names)) {
        $message_parts[] = sprintf( _n( '%d file was already in the database.', '%d files were already in the database.', count($existing_files_names), 'power-importer-pro' ), count($existing_files_names) );
    }
    if (!empty($error_messages)) {
         $message_parts[] = sprintf( _n( '%d file could not be added due to errors.', '%d files could not be added due to errors.', count($error_messages), 'power-importer-pro' ), count($error_messages) );
    }

    if (empty($new_jobs_data) && empty($existing_files_names) && empty($error_messages) ) {
        wp_send_json_success(['message' => __('No new importable files found in the upload directory.', 'power-importer-pro'), 'new_jobs' => [], 'existing_files' => [], 'errors' => []]);
        return; 
    }
    
    wp_send_json_success([
        'message' => rtrim(implode(' ', $message_parts)),
        'new_jobs' => $new_jobs_data,
        'existing_files' => $existing_files_names,
        'errors' => $error_messages,
        'scanned_directory' => $pip_upload_path
    ]);
}

/**
 * Validates CSV file format, specifically checking for required headers.
 */
function is_valid_csv_file($filepath) {
    if (!file_exists($filepath) || !is_readable($filepath)) {
        // error_log("Power Importer: File not found or not readable at {$filepath}");
        return false;
    }

    $handle = @fopen($filepath, 'r');
    if (!\$handle) {
        // error_log("Power Importer: Could not open file {$filepath}");
        return false;
    }

    \$headers = fgetcsv(\$handle);
    fclose(\$handle);

    if (\$headers === false || empty(\$headers)) {
        // error_log("Power Importer: Could not read headers or file is empty: {$filepath}");
        return false; 
    }

    $actual_headers = array_map('trim', \$headers);
    $required_headers = ['Name', 'SKU', 'Type']; 

    foreach (\$required_headers as \$required_header) {
        if (!in_array(\$required_header, \$actual_headers)) {
            return false; 
        }
    }
    
    if (count(\$actual_headers) < count(\$required_headers)) {
        return false;
    }

    return true; 
}


/**
 * Plugin activation hook.
 */
function pip_plugin_activation() {
    // Create upload directory
    $upload_dir_info = wp_upload_dir();
    $pip_upload_path = trailingslashit($upload_dir_info['basedir']) . PIP_UPLOAD_DIR_NAME;
    if ( ! file_exists( $pip_upload_path ) ) {
        wp_mkdir_p( $pip_upload_path );
        if ( ! file_exists( $pip_upload_path . '/.htaccess' ) ) {
            @file_put_contents( $pip_upload_path . '/.htaccess', 'deny from all' );
        }
        if ( ! file_exists( $pip_upload_path . '/index.html' ) ) {
            @file_put_contents( $pip_upload_path . '/index.html', '<!-- Silence is golden. -->' );
        }
    }
    
    PIP_Database::instance()->create_tables();
}
register_activation_hook( __FILE__, 'pip_plugin_activation' );

/**
 * Admin notice.
 */
function pip_show_admin_notices() {
    // Example: Display a notice if Action Scheduler was previously expected but now it's not.
    // This can be adapted for other one-time notices.
    // if (get_option('pip_show_as_deprecation_notice', true)) {
    //     echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Power Importer Pro now uses its own background processing and no longer requires Action Scheduler.', 'power-importer-pro' ) . '</p></div>';
    //     update_option('pip_show_as_deprecation_notice', false); // Show only once
    // }
}
// add_action( 'admin_notices', 'pip_show_admin_notices' ); // Uncomment if you want to show a notice.

/**
 * Handles AJAX request to reset a job.
 */
function pip_ajax_reset_job_callback() {
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
    
    $reset_data = [
        'status' => 'pending',
        'processed_rows' => 0,
        'total_rows' => 0, 
        'started_at' => null,
        'finished_at' => null,
        'error_message' => null 
    ];
    
    $updated = PIP_Database::instance()->update_job($job_id, $reset_data);
    
    if ($updated !== false) { 
        PIP_Database::instance()->add_log($job_id, __('Job has been reset to pending status by user.', 'power-importer-pro'), 'INFO');
        wp_send_json_success([
            'message' => __('Job has been reset successfully. You can now re-validate and start the import.', 'power-importer-pro'),
            'job_id' => $job_id
        ]);
    } else {
        PIP_Database::instance()->add_log($job_id, __('Failed to reset job status in the database.', 'power-importer-pro'), 'ERROR');
        wp_send_json_error(['message' => __('Failed to reset job. Please check server logs.', 'power-importer-pro')]);
    }
}

/**
 * Adds custom cron schedules.
 * Not actively used by the plugin's core import logic but kept for potential future use.
 */
function pip_add_cron_intervals($schedules) {
    if (!isset($schedules['every_minute'])){
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'power-importer-pro')
        );
    }
    return $schedules;
}
add_filter('cron_schedules', 'pip_add_cron_intervals');

/**
 * Helper function to get upload error messages.
 *
 * @param int $error_code PHP_UPLOAD_ERR_* constant.
 * @return string Translatable error message.
 */
function pip_get_upload_error_message( $error_code ) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'power-importer-pro' );
        case UPLOAD_ERR_FORM_SIZE:
            return __( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'power-importer-pro' );
        case UPLOAD_ERR_PARTIAL:
            return __( 'The uploaded file was only partially uploaded.', 'power-importer-pro' );
        case UPLOAD_ERR_NO_FILE:
            // This case is usually handled before calling this function.
            return __( 'No file was uploaded.', 'power-importer-pro' ); 
        case UPLOAD_ERR_NO_TMP_DIR:
            return __( 'Missing a temporary folder.', 'power-importer-pro' );
        case UPLOAD_ERR_CANT_WRITE:
            return __( 'Failed to write file to disk.', 'power-importer-pro' );
        case UPLOAD_ERR_EXTENSION:
            return __( 'A PHP extension stopped the file upload.', 'power-importer-pro' );
        default:
            return __( 'Unknown upload error.', 'power-importer-pro' );
    }
}

// Function to ensure upload directory exists and is writable
// This can be called at the beginning of upload/scan functions.
function pip_ensure_upload_dir() {
    $upload_dir_info = wp_upload_dir();
    $pip_upload_path = trailingslashit($upload_dir_info['basedir']) . PIP_UPLOAD_DIR_NAME;

    if (!file_exists($pip_upload_path)) {
        if (!wp_mkdir_p($pip_upload_path)) {
            return new WP_Error('dir_creation_failed', sprintf(__('Upload directory %s could not be created. Please check parent directory permissions.', 'power-importer-pro'), esc_html(trailingslashit($upload_dir_info['basedir']) . PIP_UPLOAD_DIR_NAME)));
        }
        // Add .htaccess and index.html for security
        if ( ! file_exists( $pip_upload_path . '/.htaccess' ) ) {
            @file_put_contents( $pip_upload_path . '/.htaccess', 'deny from all' );
        }
        if ( ! file_exists( $pip_upload_path . '/index.html' ) ) {
            @file_put_contents( $pip_upload_path . '/index.html', '<!-- Silence is golden. -->' );
        }
    }

    if (!is_writable($pip_upload_path)) {
        return new WP_Error('dir_not_writable', sprintf(__('Upload directory %s is not writable. Please check permissions.', 'power-importer-pro'), esc_html($pip_upload_path)));
    }
    return $pip_upload_path;
}

?>
