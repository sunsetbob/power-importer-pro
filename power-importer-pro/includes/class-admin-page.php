<?php
/**
 * 插件的后台管理页面 (V-Final.3 - UI Only Refactored for JSON table)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PIP_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
    }
    
    public function add_admin_menu() {
        add_submenu_page( 
            'edit.php?post_type=product', 
            __( 'Power Importer', 'power-importer-pro' ), 
            __( 'Power Importer', 'power-importer-pro' ), 
            'manage_woocommerce', 
            'power-importer-pro', 
            [ $this, 'render_admin_page' ] 
        );
    }

    public function render_admin_page() {
        $this->enqueue_scripts();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php
            if ( isset( $_GET['view_log'] ) && ! empty( $_GET['view_log'] ) ) {
                $this->render_log_details_page( absint( $_GET['view_log'] ) );
            } else {
                $this->render_main_page();
            }
            ?>
        </div>
        <?php
    }

    private function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-droppable');
        
        $plugin_url = plugins_url('', dirname(__FILE__)); // Correctly points to 'includes' then goes up and into 'assets'
        
        wp_enqueue_script(
            'pip-batch-processor',
            $plugin_url . '/assets/js/batch-processor.js',
            ['jquery', 'jquery-ui-droppable'],
            PIP_VERSION,
            true
        );
        
        // Get current screen to ensure localization only happens on this plugin page
        $current_screen = get_current_screen();
        $is_pip_page = ($current_screen && $current_screen->id === 'product_page_power-importer-pro');

        wp_localize_script('pip-batch-processor', 'pip_ajax_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pip_ajax_nonce'),
            'get_jobs_nonce' => wp_create_nonce('pip_get_jobs_nonce'), // Specific nonce for getting jobs
            'admin_url' => admin_url(),
            'is_pip_page' => $is_pip_page, // Let JS know if it's the correct page
            'strings' => [
                'upload_progress' => __('Uploading files...', 'power-importer-pro'),
                'validation_progress' => __('Validating CSV...', 'power-importer-pro'),
                'import_progress' => __('Importing products...', 'power-importer-pro'),
                'confirm_cancel' => __('Are you sure you want to cancel this import job?', 'power-importer-pro'),
                'confirm_delete' => __('Are you sure you want to delete this job and its logs? This action cannot be undone.', 'power-importer-pro'),
                'confirm_reset' => __('Are you sure you want to reset this job? This will stop any current processing and reset its progress to pending.', 'power-importer-pro'),
                'status_pending' => __('Pending', 'power-importer-pro'),
                'status_validated' => __('Validated', 'power-importer-pro'),
                'status_running_ajax' => __('Running (AJAX)', 'power-importer-pro'),
                'status_queued_async' => __('Queued (Background)', 'power-importer-pro'),
                'status_running_async' => __('Running (Background)', 'power-importer-pro'),
                'status_completed' => __('Completed', 'power-importer-pro'),
                'status_failed' => __('Failed', 'power-importer-pro'),
                'status_paused' => __('Paused', 'power-importer-pro'),
                'status_cancelled' => __('Cancelled', 'power-importer-pro'),
                'status_unknown' => __('Unknown', 'power-importer-pro'),
                'view_log_label' => __('View Log', 'power-importer-pro'),
                'start_label' => __('Start', 'power-importer-pro'),
                'pause_label' => __('Pause', 'power-importer-pro'),
                'resume_label' => __('Resume', 'power-importer-pro'),
                'cancel_label' => __('Cancel', 'power-importer-pro'),
                'background_label' => __('Background', 'power-importer-pro'),
                'delete_label' => __('Delete', 'power-importer-pro'),
                'reset_label' => __('Reset', 'power-importer-pro'),
            ]
        ]);
        
        wp_enqueue_style(
            'pip-admin-style',
            $plugin_url . '/assets/css/admin-style.css',
            [],
            PIP_VERSION
        );
    }

    private function render_main_page() {
        ?>
        <div class="pip-container">
            <p class="pip-subtitle"><?php _e('Professional CSV import tool with advanced features', 'power-importer-pro'); ?></p>
            
            <div class="pip-card" style="margin-bottom: 20px;">
                <div class="pip-card-header">
                    <h2 class="pip-card-title"><?php _e('System Status', 'power-importer-pro'); ?></h2>
                    <p class="pip-card-subtitle"><?php _e('Background processing capabilities', 'power-importer-pro'); ?></p>
                </div>
                <div class="pip-card-content">
                    <?php $this->render_system_status(); ?>
                </div>
            </div>
            
            <div class="pip-card pip-control-panel">
                <h2 class="pip-card-title"><?php _e('Batch Controls', 'power-importer-pro'); ?></h2>
                <div class="pip-batch-controls">
                    <button id="pip-start-all" class="pip-control-button">
                        <span class="dashicons dashicons-controls-play"></span>
                        <?php _e('Start All Pending/Paused', 'power-importer-pro'); ?>
                    </button>
                    <button id="pip-pause-all" class="pip-control-button">
                        <span class="dashicons dashicons-controls-pause"></span>
                        <?php _e('Pause All Running (AJAX)', 'power-importer-pro'); ?>
                    </button>
                    <button id="pip-scan-files" class="pip-control-button" style="background: #28a745; border-color: #28a745; color: white;">
                        <span class="dashicons dashicons-search"></span>
                        <?php _e('Scan Upload Directory', 'power-importer-pro'); ?>
                    </button>
                </div>
                <div class="pip-global-settings">
                    <div class="pip-setting-group">
                        <label class="pip-setting-label" for="pip-batch-size"><?php _e('AJAX Batch Size', 'power-importer-pro'); ?></label>
                        <input type="number" id="pip-batch-size" class="pip-setting-input" value="10" min="1" max="100" placeholder="10">
                    </div>
                    <div class="pip-setting-group">
                        <label class="pip-setting-label" for="pip-delay"><?php _e('AJAX Delay (ms)', 'power-importer-pro'); ?></label>
                        <input type="number" id="pip-delay" class="pip-setting-input" value="1000" min="100" max="5000" step="100" placeholder="1000">
                    </div>
                </div>
            </div>

            <div class="pip-card pip-upload-area">
                <div class="pip-card-header">
                    <h2 class="pip-card-title"><?php _e('Upload Import Files', 'power-importer-pro'); ?></h2>
                    <p class="pip-card-subtitle"><?php _e('Drag and drop your CSV files or click to browse', 'power-importer-pro'); ?></p>
                </div>
                <div class="pip-card-content">
                    <div id="pip-drop-zone" class="pip-drop-zone">
                        <div class="pip-drop-zone-content">
                            <div class="pip-upload-icon">
                                <span class="dashicons dashicons-upload"></span>
                            </div>
                            <h3><?php _e('Drop your CSV files here', 'power-importer-pro'); ?></h3>
                            <p><?php _e('or click the button below to browse your computer', 'power-importer-pro'); ?></p>
                            <input type="file" id="import_csv_files" name="import_csv_files[]" accept=".csv,.txt" multiple style="display: none;">
                            <button type="button" class="pip-upload-button" onclick="document.getElementById('import_csv_files').click()">
                                <?php _e('Choose Files', 'power-importer-pro'); ?>
                            </button>
                        </div>
                        <div class="pip-upload-progress" style="display: none;">
                            <div class="pip-progress-bar">
                                <div class="pip-progress-fill"></div>
                            </div>
                            <span class="pip-progress-text">0%</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pip-jobs-stats">
                <div class="pip-stat pending"><div class="pip-stat-icon"><span class="dashicons dashicons-clock"></span></div><div class="pip-stat-count" id="pip-stat-pending">0</div><div class="pip-stat-label"><?php _e('Pending/Paused', 'power-importer-pro'); ?></div></div>
                <div class="pip-stat running"><div class="pip-stat-icon"><span class="dashicons dashicons-update"></span></div><div class="pip-stat-count" id="pip-stat-running">0</div><div class="pip-stat-label"><?php _e('Running', 'power-importer-pro'); ?></div></div>
                <div class="pip-stat completed"><div class="pip-stat-icon"><span class="dashicons dashicons-yes"></span></div><div class="pip-stat-count" id="pip-stat-completed">0</div><div class="pip-stat-label"><?php _e('Completed', 'power-importer-pro'); ?></div></div>
                <div class="pip-stat failed"><div class="pip-stat-icon"><span class="dashicons dashicons-warning"></span></div><div class="pip-stat-count" id="pip-stat-failed">0</div><div class="pip-stat-label"><?php _e('Failed', 'power-importer-pro'); ?></div></div>
            </div>

            <div class="pip-card" id="import-status-area">
                <div class="pip-card-header">
                    <h2 class="pip-card-title"><?php _e('Import Jobs', 'power-importer-pro'); ?></h2>
                    <p class="pip-card-subtitle"><?php _e('Monitor and manage your import operations', 'power-importer-pro'); ?></p>
                </div>
                <div class="pip-card-content">
                    <div class="pip-bulk-actions" style="margin-bottom: 15px; display: none;">
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <span class="pip-selected-count">0 selected</span>
                            <button class="pip-bulk-action-btn" data-action="start" style="background: #007cba; color: white;"><span class="dashicons dashicons-controls-play"></span> <?php _e('Start Selected', 'power-importer-pro'); ?></button>
                            <button class="pip-bulk-action-btn" data-action="pause" style="background: #f0ad4e; color: white;"><span class="dashicons dashicons-controls-pause"></span> <?php _e('Pause Selected (AJAX)', 'power-importer-pro'); ?></button>
                            <button class="pip-bulk-action-btn" data-action="cancel" style="background: #d9534f; color: white;"><span class="dashicons dashicons-no"></span> <?php _e('Cancel Selected', 'power-importer-pro'); ?></button>
                            <button class="pip-bulk-action-btn" data-action="delete" style="background: #e74c3c; color: white;"><span class="dashicons dashicons-trash"></span> <?php _e('Delete Selected', 'power-importer-pro'); ?></button>
                        </div>
                    </div>
                    
                    <div id="pip-jobs-table-container">
                        <?php $this->render_jobs_table_shell(); /* Changed from render_jobs_table_content */ ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_system_status() {
        // Check if WP-Cron is disabled via constant
        $cron_disabled_const = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
        
        // Check if loopback requests might be an issue (simplified check)
        $loopback_requests_working = true; // Assume working unless a test fails
        $response = wp_remote_get(admin_url('admin-ajax.php'), ['timeout' => 5, 'sslverify' => apply_filters('https_local_ssl_verify', false)]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $loopback_requests_working = false;
            $loopback_error = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
        }

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="pip-status-item">
                <h4><?php _e('PHP Memory Limit', 'power-importer-pro'); ?></h4>
                <span class="pip-status-badge pip-status-info">
                    <span class="dashicons dashicons-performance"></span>
                    <?php echo ini_get('memory_limit'); ?>
                </span>
                <p><small><?php _e('Affects processing large files.', 'power-importer-pro'); ?></small></p>
            </div>
            
            <div class="pip-status-item">
                <h4><?php _e('PHP Max Execution Time', 'power-importer-pro'); ?></h4>
                <span class="pip-status-badge pip-status-info">
                    <span class="dashicons dashicons-clock"></span>
                    <?php echo ini_get('max_execution_time'); ?>s
                </span>
                 <p><small><?php _e('Affects AJAX mode processing time per batch.', 'power-importer-pro'); ?></small></p>
            </div>

            <div class="pip-status-item">
                <h4><?php _e('Background Processing (Async Loopback)', 'power-importer-pro'); ?></h4>
                <?php if ($loopback_requests_working): ?>
                    <span class="pip-status-badge pip-status-completed">
                        <span class="dashicons dashicons-yes"></span>
                        <?php _e('Likely Available', 'power-importer-pro'); ?>
                    </span>
                    <p><small><?php _e('Async background imports should work.', 'power-importer-pro'); ?></small></p>
                <?php else: ?>
                    <span class="pip-status-badge pip-status-failed">
                        <span class="dashicons dashicons-warning"></span>
                        <?php _e('May Be Issues', 'power-importer-pro'); ?>
                    </span>
                    <p><small><?php printf(__('Loopback request test failed (Error: %s). Background imports might not work reliably.', 'power-importer-pro'), esc_html($loopback_error)); ?></small></p>
                <?php endif; ?>
            </div>
            <div class="pip-status-item">
                <h4><?php _e('WP-Cron Status', 'power-importer-pro'); ?></h4>
                 <?php if ($cron_disabled_const): ?>
                    <span class="pip-status-badge pip-status-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <?php _e('Disabled (DISABLE_WP_CRON)', 'power-importer-pro'); ?>
                    </span>
                    <p><small><?php _e('Plugin relies on Async Loopback, not WP-Cron.', 'power-importer-pro'); ?></small></p>
                <?php else: ?>
                     <span class="pip-status-badge pip-status-info">
                        <span class="dashicons dashicons-info"></span>
                        <?php _e('Enabled (Not used by this plugin for imports)', 'power-importer-pro'); ?>
                    </span>
                     <p><small><?php _e('This plugin uses its own async background processing.', 'power-importer-pro'); ?></small></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renders the static shell of the jobs table.
     * The table body will be populated by JavaScript using JSON data.
     */
    public function render_jobs_table_shell() {
        ?>
        <table class="wp-list-table widefat fixed striped pip-jobs-table">
            <thead>
                <tr>
                    <th style="width: 30px; padding-left: 10px;"><input type="checkbox" id="pip-select-all-jobs" title="<?php _e('Select All', 'power-importer-pro'); ?>"></th>
                    <th style="width: 60px;"><?php _e('ID', 'power-importer-pro'); ?></th>
                    <th><?php _e('File Name', 'power-importer-pro'); ?></th>
                    <th style="width: 150px;"><?php _e('Status', 'power-importer-pro'); ?></th>
                    <th style="width: 220px;"><?php _e('Progress', 'power-importer-pro'); ?></th>
                    <th style="width: 160px;"><?php _e('Started At', 'power-importer-pro'); ?></th>
                    <th style="width: 120px;"><?php _e('Duration', 'power-importer-pro'); ?></th>
                    <th style="width: 200px;"><?php _e('Actions', 'power-importer-pro'); ?></th>
                </tr>
            </thead>
            <tbody id="pip-jobs-table-body">
                <?php // JavaScript will populate this ?>
            </tbody>
        </table>
        <div class="pip-empty-state" id="pip-jobs-empty-state" style="display: none; text-align: center; padding: 40px;">
            <div class="pip-empty-state-icon"><span class="dashicons dashicons-list-view" style="font-size: 48px; color: #ccc;"></span></div>
            <h3><?php _e('No Import Jobs Found', 'power-importer-pro'); ?></h3>
            <p><?php _e('Upload some CSV files to get started or scan the upload directory.', 'power-importer-pro'); ?></p>
        </div>
        <?php
    }

    private function render_log_details_page( $job_id ) {
        $job = pip_db()->get_job($job_id);
        if ( ! $job ) { 
            echo '<div class="notice notice-error"><p>' . __( 'Job not found.', 'power-importer-pro' ) . '</p></div>'; 
            return; 
        }
        $logs = pip_db()->get_logs_for_job($job_id);
        $back_link = remove_query_arg('view_log', admin_url('edit.php?post_type=product&page=power-importer-pro'));
        ?>
        <h2 style="margin-bottom: 10px;"><?php printf( __( 'Log for Job #%d: %s', 'power-importer-pro' ), $job_id, esc_html($job->file_name) ); ?></h2>
        <p style="margin-bottom: 20px;"><a href="<?php echo esc_url($back_link); ?>" class="button">&larr; <?php _e('Back to All Jobs', 'power-importer-pro'); ?></a></p>
        
        <div class="pip-card">
            <div class="pip-card-content">
                <div id="log-viewer" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; max-height: 600px; overflow-y: auto; font-family: monospace; white-space: pre-wrap; line-height: 1.6;">
                    <?php if ( empty($logs) ) : ?>
                        <p><?php _e('No log entries found for this job.', 'power-importer-pro'); ?></p>
                    <?php else : ?>
                        <?php foreach ( $logs as $log_entry ) : 
                            $level_class = 'log-level-' . strtolower(esc_attr($log_entry->log_level)); 
                            $level_color = 'inherit';
                            switch (strtoupper($log_entry->log_level)) {
                                case 'ERROR': $level_color = '#dc3545'; break;
                                case 'WARNING': $level_color = '#ffc107'; break;
                                case 'SUCCESS': $level_color = '#28a745'; break;
                                case 'INFO': $level_color = '#17a2b8'; break;
                            }
                        ?>
                        <div class="<?php echo $level_class; ?>" style="padding: 3px 0; border-bottom: 1px dotted #eee;">
                            <strong style="color: #888;">[<?php echo esc_html(date_i18n( get_option( 'date_format' ) . ' ' . get_option('time_format'), strtotime( $log_entry->log_timestamp ) )); ?>]</strong> 
                            <strong style="color: <?php echo $level_color; ?>;">[<?php echo esc_html(strtoupper($log_entry->log_level)); ?>]</strong>: 
                            <span><?php echo nl2br(esc_html($log_entry->message)); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
?>
