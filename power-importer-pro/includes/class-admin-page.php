<?php
/**
 * 插件的后台管理页面 (V-Final.3 - UI Only)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PIP_Admin_Page {

    public function __construct() {
        // 构造函数现在只负责注册菜单
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        // 文件上传处理现在由主插件文件中的全局函数处理
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
        // 加载脚本和样式
        $this->enqueue_scripts();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php
            if ( isset( $_GET['view_log'] ) && ! empty( $_GET['view_log'] ) ) {
                $this->render_log_details_page( (int) $_GET['view_log'] );
            } else {
                // 不再处理POST请求，完全使用AJAX
                $this->render_main_page();
            }
            ?>
        </div>
        
        <!-- 调试脚本 -->
        <script>
        jQuery(document).ready(function($) {
            console.log('=== PIP Debug Information ===');
            console.log('jQuery loaded:', typeof $ !== 'undefined');
            console.log('pip_ajax_vars available:', typeof pip_ajax_vars !== 'undefined');
            if (typeof pip_ajax_vars !== 'undefined') {
                console.log('AJAX URL:', pip_ajax_vars.ajax_url);
                console.log('Nonce:', pip_ajax_vars.nonce);
            }
            console.log('PipBatchProcessor available:', typeof window.pipBatchProcessor !== 'undefined');
            
            // 测试按钮点击
            $('#pip-start-all').on('click', function() {
                console.log('Start All button clicked!');
            });
            
            $('#pip-scan-files').on('click', function() {
                console.log('Scan Files button clicked!');
            });
            
            // 测试AJAX连接
            setTimeout(function() {
                console.log('Testing AJAX connection...');
                $.ajax({
                    url: pip_ajax_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'pip_get_jobs_table',
                        nonce: pip_ajax_vars.nonce
                    },
                    success: function(response) {
                        console.log('AJAX test successful:', response);
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX test failed:', status, error);
                        console.error('Response:', xhr.responseText);
                    }
                });
            }, 2000);
        });
        </script>
        <?php
    }

    /**
     * 加载脚本和样式
     */
    private function enqueue_scripts() {
        // 加载jQuery和相关脚本
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-droppable');
        
        // 获取正确的插件URL - 从插件根目录开始
        $plugin_url = plugins_url('', dirname(__FILE__));
        
        // 加载自定义脚本
        wp_enqueue_script(
            'pip-batch-processor',
            $plugin_url . '/assets/js/batch-processor.js',
            ['jquery', 'jquery-ui-droppable'],
            PIP_VERSION,
            true
        );
        
        // 本地化脚本
        wp_localize_script('pip-batch-processor', 'pip_ajax_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pip_ajax_nonce'),
            'strings' => [
                'upload_progress' => __('Uploading files...', 'power-importer-pro'),
                'validation_progress' => __('Validating CSV...', 'power-importer-pro'),
                'import_progress' => __('Importing products...', 'power-importer-pro'),
                'confirm_cancel' => __('Are you sure you want to cancel this import?', 'power-importer-pro'),
                'confirm_delete' => __('Are you sure you want to delete this job?', 'power-importer-pro'),
            ]
        ]);
        
        // 加载样式
        wp_enqueue_style(
            'pip-admin-style',
            $plugin_url . '/assets/css/admin-style.css',
            [],
            PIP_VERSION
        );
        
        // 添加调试信息到页面
        add_action('admin_footer', function() use ($plugin_url) {
            echo '<script>console.log("PIP Debug: Plugin URL = ' . esc_js($plugin_url) . '");</script>';
            echo '<script>console.log("PIP Debug: Script URL = ' . esc_js($plugin_url . '/assets/js/batch-processor.js') . '");</script>';
        });
    }

    private function render_main_page() {
        ?>
        <div class="pip-container">
            <p class="pip-subtitle"><?php _e('Professional CSV import tool with advanced features', 'power-importer-pro'); ?></p>
            
            <!-- 系统状态面板 -->
            <div class="pip-card" style="margin-bottom: 20px;">
                <div class="pip-card-header">
                    <h2 class="pip-card-title"><?php _e('System Status', 'power-importer-pro'); ?></h2>
                    <p class="pip-card-subtitle"><?php _e('Background processing capabilities', 'power-importer-pro'); ?></p>
                </div>
                <div class="pip-card-content">
                    <?php $this->render_system_status(); ?>
                </div>
            </div>
            
            <!-- 批量控制面板 -->
            <div class="pip-card pip-control-panel">
                <h2 class="pip-card-title"><?php _e('Batch Controls', 'power-importer-pro'); ?></h2>
                <div class="pip-batch-controls">
                    <button id="pip-start-all" class="pip-control-button">
                        <span class="dashicons dashicons-controls-play"></span>
                        <?php _e('Start All Jobs', 'power-importer-pro'); ?>
                    </button>
                    <button id="pip-pause-all" class="pip-control-button">
                        <span class="dashicons dashicons-controls-pause"></span>
                        <?php _e('Pause All Jobs', 'power-importer-pro'); ?>
                    </button>
                    <button id="pip-scan-files" class="pip-control-button" style="background: #28a745; border-color: #28a745; color: white;">
                        <span class="dashicons dashicons-search"></span>
                        <?php _e('Scan Upload Directory', 'power-importer-pro'); ?>
                    </button>
                </div>
                <div class="pip-global-settings">
                    <div class="pip-setting-group">
                        <label class="pip-setting-label" for="pip-processing-mode"><?php _e('Processing Mode', 'power-importer-pro'); ?></label>
                        <select id="pip-processing-mode" class="pip-setting-input">
                            <option value="ajax"><?php _e('Real-time (AJAX)', 'power-importer-pro'); ?></option>
                            <option value="background"><?php _e('Background Processing', 'power-importer-pro'); ?></option>
                            <option value="auto"><?php _e('Auto (Recommended)', 'power-importer-pro'); ?></option>
                        </select>
                    </div>
                    <div class="pip-setting-group">
                        <label class="pip-setting-label" for="pip-batch-size"><?php _e('Batch Size', 'power-importer-pro'); ?></label>
                        <input type="number" id="pip-batch-size" class="pip-setting-input" value="10" min="5" max="50" placeholder="10">
                    </div>
                    <div class="pip-setting-group">
                        <label class="pip-setting-label" for="pip-delay"><?php _e('Delay (ms)', 'power-importer-pro'); ?></label>
                        <input type="number" id="pip-delay" class="pip-setting-input" value="1000" min="500" max="5000" step="500" placeholder="1000">
                    </div>
                </div>
            </div>

            <!-- 拖拽上传区域 -->
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

            <!-- 统计概览 -->
            <div class="pip-jobs-stats">
                <div class="pip-stat pending">
                    <div class="pip-stat-icon">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="pip-stat-count">0</div>
                    <div class="pip-stat-label"><?php _e('Pending', 'power-importer-pro'); ?></div>
                </div>
                <div class="pip-stat running">
                    <div class="pip-stat-icon">
                        <span class="dashicons dashicons-update"></span>
                    </div>
                    <div class="pip-stat-count">0</div>
                    <div class="pip-stat-label"><?php _e('Running', 'power-importer-pro'); ?></div>
                </div>
                <div class="pip-stat completed">
                    <div class="pip-stat-icon">
                        <span class="dashicons dashicons-yes"></span>
                    </div>
                    <div class="pip-stat-count">0</div>
                    <div class="pip-stat-label"><?php _e('Completed', 'power-importer-pro'); ?></div>
                </div>
                <div class="pip-stat failed">
                    <div class="pip-stat-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="pip-stat-count">0</div>
                    <div class="pip-stat-label"><?php _e('Failed', 'power-importer-pro'); ?></div>
                </div>
            </div>

            <!-- 任务列表 -->
            <div class="pip-card" id="import-status-area">
                <div class="pip-card-header">
                    <h2 class="pip-card-title"><?php _e('Import Jobs', 'power-importer-pro'); ?></h2>
                    <p class="pip-card-subtitle"><?php _e('Monitor and manage your import operations', 'power-importer-pro'); ?></p>
                </div>
                <div class="pip-card-content">
                    <!-- 批量操作工具栏 -->
                    <div class="pip-bulk-actions" style="margin-bottom: 15px; display: none;">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <span class="pip-selected-count">0 selected</span>
                            <button class="pip-bulk-action-btn" data-action="start" style="background: #007cba; color: white;">
                                <span class="dashicons dashicons-controls-play"></span>
                                <?php _e('Start Selected', 'power-importer-pro'); ?>
                            </button>
                            <button class="pip-bulk-action-btn" data-action="pause" style="background: #f0ad4e; color: white;">
                                <span class="dashicons dashicons-controls-pause"></span>
                                <?php _e('Pause Selected', 'power-importer-pro'); ?>
                            </button>
                            <button class="pip-bulk-action-btn" data-action="cancel" style="background: #d9534f; color: white;">
                                <span class="dashicons dashicons-no"></span>
                                <?php _e('Cancel Selected', 'power-importer-pro'); ?>
                            </button>
                            <button class="pip-bulk-action-btn" data-action="delete" style="background: #e74c3c; color: white;">
                                <span class="dashicons dashicons-trash"></span>
                                <?php _e('Delete Selected', 'power-importer-pro'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <?php $this->render_jobs_table(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染系统状态
     */
    private function render_system_status() {
        // 检测wp-cron状态
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $cron_url_accessible = $this->check_wp_cron_accessibility();
        
        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="pip-status-item">
                <h4><?php _e('WordPress Cron', 'power-importer-pro'); ?></h4>
                <?php if ($cron_disabled): ?>
                    <span class="pip-status-badge pip-status-failed">
                        <span class="dashicons dashicons-warning"></span>
                        <?php _e('Disabled', 'power-importer-pro'); ?>
                    </span>
                    <p><small><?php _e('DISABLE_WP_CRON is set to true', 'power-importer-pro'); ?></small></p>
                <?php elseif (!$cron_url_accessible): ?>
                    <span class="pip-status-badge pip-status-failed">
                        <span class="dashicons dashicons-warning"></span>
                        <?php _e('Not Accessible', 'power-importer-pro'); ?>
                    </span>
                    <p><small><?php _e('wp-cron.php is blocked by server configuration', 'power-importer-pro'); ?></small></p>
                <?php else: ?>
                    <span class="pip-status-badge pip-status-completed">
                        <span class="dashicons dashicons-yes"></span>
                        <?php _e('Available', 'power-importer-pro'); ?>
                    </span>
                    <p><small><?php _e('WordPress Cron is working properly', 'power-importer-pro'); ?></small></p>
                <?php endif; ?>
            </div>
            
            <div class="pip-status-item">
                <h4><?php _e('Background Processing', 'power-importer-pro'); ?></h4>
                <?php if ($cron_disabled || !$cron_url_accessible): ?>
                    <span class="pip-status-badge pip-status-background_processing">
                        <span class="dashicons dashicons-cloud"></span>
                        <?php _e('Async Mode', 'power-importer-pro'); ?>
                    </span>
                    <p><small><?php _e('Using async processing (wp_remote_post)', 'power-importer-pro'); ?></small></p>
                <?php else: ?>
                    <span class="pip-status-badge pip-status-completed">
                        <span class="dashicons dashicons-clock"></span>
                        <?php _e('Cron Mode', 'power-importer-pro'); ?>
                    </span>
                    <p><small><?php _e('Using WordPress Cron for background tasks', 'power-importer-pro'); ?></small></p>
                <?php endif; ?>
            </div>
            
            <div class="pip-status-item">
                <h4><?php _e('Memory Limit', 'power-importer-pro'); ?></h4>
                <span class="pip-status-badge pip-status-completed">
                    <span class="dashicons dashicons-performance"></span>
                    <?php echo ini_get('memory_limit'); ?>
                </span>
                <p><small><?php _e('Current PHP memory limit', 'power-importer-pro'); ?></small></p>
            </div>
            
            <div class="pip-status-item">
                <h4><?php _e('Max Execution Time', 'power-importer-pro'); ?></h4>
                <span class="pip-status-badge pip-status-completed">
                    <span class="dashicons dashicons-clock"></span>
                    <?php echo ini_get('max_execution_time'); ?>s
                </span>
                <p><small><?php _e('PHP script execution time limit', 'power-importer-pro'); ?></small></p>
            </div>
        </div>
        
        <script>
        // 设置全局变量供JavaScript使用
        window.pip_cron_disabled = <?php echo json_encode($cron_disabled || !$cron_url_accessible); ?>;
        </script>
        <?php
    }
    
    /**
     * 检查wp-cron.php是否可访问
     */
    private function check_wp_cron_accessibility() {
        $cron_url = site_url('wp-cron.php');
        $response = wp_remote_get($cron_url, [
            'timeout' => 5,
            'blocking' => true,
            'sslverify' => false
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * 渲染任务表格容器
     */
    private function render_jobs_table() {
        ?>
        <div id="pip-jobs-table-container">
            <?php $this->render_jobs_table_content(); ?>
        </div>
        <?php
    }

    public function render_jobs_table_content() {
        $jobs = pip_db()->get_recent_jobs();
        ?>
        <style>
        .pip-control-panel {
            background: #f9f9f9;
            border-left: 4px solid #007cba;
        }

        .pip-batch-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .pip-global-settings {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: auto;
        }

        .pip-global-settings input {
            width: 80px;
        }

        .pip-drop-zone {
            border: 2px dashed #ccd0d4;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            background: #fafafa;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .pip-drop-zone:hover,
        .pip-drop-zone.drag-over {
            border-color: #007cba;
            background: #f0f8ff;
        }

        .pip-drop-zone-content h3 {
            margin: 10px 0;
            color: #555;
        }

        .pip-drop-zone-content .dashicons {
            font-size: 48px;
            color: #007cba;
        }

        .pip-progress-bar {
            width: 100%;
            height: 20px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }

        .pip-progress-fill {
            height: 100%;
            background: linear-gradient(45deg, #007cba, #005a8b);
            width: 0%;
            transition: width 0.3s ease;
        }

        .pip-jobs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .pip-jobs-stats {
            display: flex;
            gap: 20px;
        }

        .pip-stat {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 8px 12px;
            border-radius: 4px;
            background: #f0f0f0;
        }

        .pip-stat.pending { border-left: 3px solid #f0ad4e; }
        .pip-stat.running { border-left: 3px solid #007cba; }
        .pip-stat.completed { border-left: 3px solid #5cb85c; }
        .pip-stat.failed { border-left: 3px solid #d9534f; }

        .pip-job-controls {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .pip-job-progress {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .pip-job-progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }

        .pip-job-progress-fill {
            height: 100%;
            background: #007cba;
            transition: width 0.3s ease;
        }

        .pip-status-running { color: #007cba; font-weight: bold; }
        .pip-status-completed { color: #5cb85c; font-weight: bold; }
        .pip-status-failed { color: #d9534f; font-weight: bold; }
        .pip-status-pending { color: #f0ad4e; }
        .pip-status-paused { color: #6c757d; }
        .pip-status-cancelled { color: #6c757d; text-decoration: line-through; }
        .pip-status-validated { color: #17a2b8; }
        </style>

        <table class="wp-list-table widefat fixed striped pip-jobs-table">
            <thead>
                <tr>
                    <th style="width: 30px;">
                        <input type="checkbox" id="pip-select-all-jobs" title="<?php _e('Select All', 'power-importer-pro'); ?>">
                    </th>
                    <th style="width: 50px;">ID</th>
                    <th>File Name</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 200px;">Progress</th>
                    <th style="width: 150px;">Started At</th>
                    <th style="width: 150px;">Duration</th>
                    <th style="width: 200px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $jobs ) ) : ?>
                    <tr>
                        <td colspan="8">
                            <div class="pip-empty-state">
                                <div class="pip-empty-state-icon">
                                    <span class="dashicons dashicons-upload"></span>
                                </div>
                                <h3><?php _e('No Import Jobs Found', 'power-importer-pro'); ?></h3>
                                <p><?php _e('Upload some CSV files to get started with importing your products!', 'power-importer-pro'); ?></p>
                            </div>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $jobs as $job ) : ?>
                        <tr class="job-row" id="job-<?php echo $job->id; ?>" data-job-id="<?php echo $job->id; ?>" data-processed="<?php echo $job->processed_rows; ?>" data-total="<?php echo $job->total_rows; ?>">
                            <td>
                                <input type="checkbox" class="pip-job-checkbox" value="<?php echo $job->id; ?>" data-status="<?php echo esc_attr($job->status); ?>">
                            </td>
                            <td>#<?php echo (int)$job->id; ?></td>
                            <td>
                                <strong><?php echo esc_html($job->file_name); ?></strong>
                                <?php if ($job->total_rows > 0): ?>
                                    <br><small style="color: #717171;"><?php echo number_format($job->total_rows); ?> rows</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="pip-status-badge pip-status-<?php echo esc_attr($job->status); ?> job-status">
                                    <?php echo esc_html(ucfirst($job->status)); ?>
                                </span>
                            </td>
                            <td class="pip-job-progress">
                                <?php if ($job->total_rows > 0): ?>
                                    <?php 
                                    $percentage = round(($job->processed_rows / $job->total_rows) * 100, 1);
                                    ?>
                                    <div class="pip-job-progress-text job-progress">
                                        <?php echo "{$job->processed_rows} / {$job->total_rows} ({$percentage}%)"; ?>
                                    </div>
                                    <div class="pip-job-progress-bar">
                                        <div class="pip-job-progress-fill progress-bar" style="width: <?php echo $percentage; ?>%;"></div>
                                    </div>
                                <?php else: ?>
                                    <div class="pip-job-progress-text job-progress"><?php echo $job->processed_rows; ?> / ?</div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $job->started_at ? date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $job->started_at ) ) : '—'; ?></td>
                            <td>
                                <?php 
                                if ($job->started_at) {
                                    $start = strtotime($job->started_at);
                                    $end = $job->finished_at ? strtotime($job->finished_at) : time();
                                    $duration = $end - $start;
                                    echo $this->format_duration($duration);
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <?php $log_link = add_query_arg( ['page' => 'power-importer-pro', 'view_log' => $job->id], admin_url('edit.php?post_type=product') ); ?>
                                    <a href="<?php echo esc_url( $log_link ); ?>" class="pip-action-button" title="<?php _e('View Logs', 'power-importer-pro'); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </a>
                                    
                                    <?php if ( $job->status === 'pending' || $job->status === 'uploaded' || $job->status === 'validated' ): ?>
                                        <button class="pip-action-button primary pip-start-import" data-job-id="<?php echo $job->id; ?>" title="<?php _e('Start Import', 'power-importer-pro'); ?>">
                                            <span class="dashicons dashicons-controls-play"></span>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ( $job->status === 'running' ): ?>
                                        <button class="pip-action-button pip-pause-import" data-job-id="<?php echo $job->id; ?>" title="<?php _e('Pause Import', 'power-importer-pro'); ?>">
                                            <span class="dashicons dashicons-controls-pause"></span>
                                        </button>
                                        <button class="pip-action-button pip-background-import" data-job-id="<?php echo $job->id; ?>" title="<?php _e('Switch to Background Processing', 'power-importer-pro'); ?>" style="background: #28a745; color: white; border-color: #28a745;">
                                            <span class="dashicons dashicons-cloud"></span>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ( $job->status === 'paused' ): ?>
                                        <button class="pip-action-button primary pip-resume-import" data-job-id="<?php echo $job->id; ?>" title="<?php _e('Resume Import', 'power-importer-pro'); ?>">
                                            <span class="dashicons dashicons-controls-play"></span>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ( in_array($job->status, ['running', 'paused']) ): ?>
                                        <button class="pip-action-button pip-cancel-import" data-job-id="<?php echo $job->id; ?>" title="<?php _e('Cancel Import', 'power-importer-pro'); ?>">
                                            <span class="dashicons dashicons-no"></span>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ( in_array($job->status, ['completed', 'failed', 'cancelled']) ): ?>
                                        <button class="pip-action-button pip-delete-job" data-job-id="<?php echo $job->id; ?>" title="<?php _e('Delete Job', 'power-importer-pro'); ?>" style="color: #e74c3c; border-color: #e74c3c;">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * 格式化持续时间
     */
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return sprintf(__('%ds', 'power-importer-pro'), $seconds);
        } elseif ($seconds < 3600) {
            return sprintf(__('%dm %ds', 'power-importer-pro'), floor($seconds / 60), $seconds % 60);
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return sprintf(__('%dh %dm', 'power-importer-pro'), $hours, $minutes);
        }
    }

    private function render_log_details_page( $job_id ) {
        $job = pip_db()->get_job($job_id);
        if ( ! $job ) { echo '<div class="error"><p>' . __( 'Job not found.', 'power-importer-pro' ) . '</p></div>'; return; }
        $logs = pip_db()->get_logs_for_job($job_id);
        $back_link = remove_query_arg('view_log', admin_url('edit.php?post_type=product&page=power-importer-pro'));
        ?>
        <h2><?php printf( __( 'Log for Job #%d: %s', 'power-importer-pro' ), $job_id, esc_html($job->file_name) ); ?></h2>
        <p><a href="<?php echo esc_url($back_link); ?>">← <?php _e('Back to All Jobs', 'power-importer-pro'); ?></a></p>
        <div id="log-viewer" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; max-height: 600px; overflow-y: auto; font-family: monospace; white-space: pre-wrap; line-height: 1.6;">
            <?php if ( empty($logs) ) : ?>
                <p><?php _e('No log entries found for this job.', 'power-importer-pro'); ?></p>
            <?php else : ?>
                <?php foreach ( $logs as $log_entry ) : $level_class = 'log-level-' . strtolower(esc_attr($log_entry->log_level)); ?>
                    <div class="<?php echo $level_class; ?>"><strong>[<?php echo esc_html(date_i18n( 'Y-m-d H:i:s', strtotime($log_entry->log_timestamp))); ?>]</strong> <strong>[<?php echo esc_html($log_entry->log_level); ?>]</strong>: <?php echo esc_html($log_entry->message); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <style>.log-level-success { color: green; } .log-level-error { color: red; font-weight: bold; } .log-level-warning { color: orange; } .log-level-info { color: #555; }</style>
        <?php
    }

    private function get_upload_error_message( $error_code ) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE: return __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'power-importer-pro' );
            case UPLOAD_ERR_FORM_SIZE: return __( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'power-importer-pro' );
            case UPLOAD_ERR_PARTIAL: return __( 'The uploaded file was only partially uploaded.', 'power-importer-pro' );
            case UPLOAD_ERR_NO_FILE: return __( 'No file was uploaded.', 'power-importer-pro' );
            case UPLOAD_ERR_NO_TMP_DIR: return __( 'Missing a temporary folder.', 'power-importer-pro' );
            case UPLOAD_ERR_CANT_WRITE: return __( 'Failed to write file to disk.', 'power-importer-pro' );
            case UPLOAD_ERR_EXTENSION: return __( 'A PHP extension stopped the file upload.', 'power-importer-pro' );
            default: return __( 'Unknown upload error.', 'power-importer-pro' );
        }
    }
}