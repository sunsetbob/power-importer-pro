/**
 * Power Importer Pro - 批量处理控制器
 * 实现AJAX分片导入和断点续传功能
 */

// 调试模式
const PIP_DEBUG = true;

function pipLog(message, data = null) {
    if (PIP_DEBUG) {
        console.log('[PIP Debug]', message, data || '');
    }
}

function pipError(message, error = null) {
    console.error('[PIP Error]', message, error || '');
}

// 确保jQuery可用
if (typeof jQuery === 'undefined') {
    pipError('jQuery is not loaded!');
}

// 检查pip_ajax_vars
if (typeof pip_ajax_vars === 'undefined') {
    pipError('pip_ajax_vars is not defined! AJAX functionality will not work.');
    // 创建一个临时的对象避免错误
    window.pip_ajax_vars = {
        ajax_url: '/wp-admin/admin-ajax.php',
        nonce: 'missing'
    };
}

class PipBatchProcessor {
    constructor() {
        pipLog('Initializing PipBatchProcessor...');
        
        this.jobs = new Map(); // 存储所有任务
        this.currentJob = null; // 当前处理的任务
        this.isProcessing = false; // 全局处理状态
        this.batchSize = 10; // 批次大小
        this.processDelay = 1000; // 批次间延迟
        this.maxRetries = 3; // 最大重试次数
        
        pipLog('pip_ajax_vars loaded:', pip_ajax_vars);
        
        this.init();
    }
    
    init() {
        pipLog('Initializing batch processor...');
        
        this.bindEvents();
        this.loadExistingJobs();
        this.startAutoRefresh();
        
        // 测试所有按钮是否存在
        setTimeout(() => {
            this.testButtonsExistence();
        }, 2000);
    }
    
    bindEvents() {
        // 文件上传事件
        jQuery(document).on('change', '#import_csv_files', (e) => {
            this.handleFileSelection(e);
        });
        
        // 开始导入按钮
        jQuery(document).on('click', '.pip-start-import', (e) => {
            const jobId = jQuery(e.target).data('job-id');
            this.startImport(jobId);
        });
        
        // 暂停/恢复按钮
        jQuery(document).on('click', '.pip-pause-import', (e) => {
            const jobId = jQuery(e.target).data('job-id');
            this.pauseImport(jobId);
        });
        
        jQuery(document).on('click', '.pip-resume-import', (e) => {
            const jobId = jQuery(e.target).data('job-id');
            this.resumeImport(jobId);
        });
        
        // 取消按钮
        jQuery(document).on('click', '.pip-cancel-import', (e) => {
            const jobId = jQuery(e.target).data('job-id');
            this.cancelImport(jobId);
        });
        
        // 后台处理按钮
        jQuery(document).on('click', '.pip-background-import', (e) => {
            const jobId = jQuery(e.target).data('job-id');
            this.enableBackgroundMode(jobId);
        });
        
        // 批量操作
        jQuery(document).on('click', '#pip-start-all', () => {
            this.startAllJobs();
        });
        
        jQuery(document).on('click', '#pip-pause-all', () => {
            this.pauseAllJobs();
        });
        
        // 文件扫描
        jQuery(document).on('click', '#pip-scan-files', () => {
            this.scanUploadDirectory();
        });
        
        // 多选功能
        jQuery(document).on('change', '#pip-select-all-jobs', (e) => {
            const isChecked = jQuery(e.target).is(':checked');
            jQuery('.pip-job-checkbox').prop('checked', isChecked);
            this.updateBulkActionsVisibility();
        });
        
        jQuery(document).on('change', '.pip-job-checkbox', () => {
            this.updateBulkActionsVisibility();
        });
        
        // 批量操作按钮
        jQuery(document).on('click', '.pip-bulk-action-btn', (e) => {
            const action = jQuery(e.target).closest('.pip-bulk-action-btn').data('action');
            this.handleBulkAction(action);
        });
        
        // 重置卡住的任务
        jQuery(document).on('click', '.pip-reset-job', (e) => {
            const jobId = jQuery(e.target).closest('.pip-reset-job').data('job-id');
            if (confirm(`Are you sure you want to reset job #${jobId}? This will stop the current process and reset the job to pending status.`)) {
                this.resetStuckJob(jobId);
            }
        });
        
        // 拖拽上传功能
        this.initDragAndDrop();
    }
    
    /**
     * 初始化拖拽上传功能
     */
    initDragAndDrop() {
        const dropZone = jQuery('#pip-drop-zone');
        
        // 防止默认拖拽行为
        jQuery(document).on('dragover dragenter', (e) => {
            e.preventDefault();
            e.stopPropagation();
        });
        
        // 拖拽进入
        dropZone.on('dragenter dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.addClass('drag-over');
        });
        
        // 拖拽离开
        dropZone.on('dragleave', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.removeClass('drag-over');
        });
        
        // 文件放置
        dropZone.on('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.removeClass('drag-over');
            
            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                this.handleDroppedFiles(files);
            }
        });
        
        // 点击上传区域
        dropZone.on('click', () => {
            jQuery('#import_csv_files').click();
        });
    }
    
    /**
     * 处理拖拽的文件
     */
    handleDroppedFiles(files) {
        // 验证文件类型
        const validFiles = [];
        const invalidFiles = [];
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            if (file.type === 'text/csv' || file.name.toLowerCase().endsWith('.csv')) {
                validFiles.push(file);
            } else {
                invalidFiles.push(file.name);
            }
        }
        
        if (invalidFiles.length > 0) {
            this.showError(`Invalid file types: ${invalidFiles.join(', ')}. Only CSV files are allowed.`);
        }
        
        if (validFiles.length === 0) {
            return;
        }
        
        // 创建FormData并上传
        const formData = new FormData();
        formData.append('action', 'pip_upload_files');
        formData.append('nonce', pip_ajax_vars.nonce);
        
        for (let i = 0; i < validFiles.length; i++) {
            formData.append('import_csv_files[]', validFiles[i]);
        }
        
        this.uploadFiles(formData, validFiles.length);
    }
    
    /**
     * 处理文件选择
     */
    handleFileSelection(e) {
        const files = e.target.files;
        if (files.length === 0) return;
        
        const formData = new FormData();
        formData.append('action', 'pip_upload_files');
        formData.append('nonce', pip_ajax_vars.nonce);
        
        for (let i = 0; i < files.length; i++) {
            formData.append('import_csv_files[]', files[i]);
        }
        
        this.uploadFiles(formData, files.length);
        
        // 清空文件输入框
        jQuery(e.target).val('');
    }
    
    /**
     * 上传文件
     */
    uploadFiles(formData, fileCount) {
        this.showProgress(`Uploading ${fileCount} files...`);
        
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: (response) => {
                if (response.success) {
                    this.showSuccess(`${fileCount} files uploaded successfully`);
                    this.loadExistingJobs();
                } else {
                    this.showError(response.data.message || 'Upload failed');
                }
            },
            error: () => {
                this.showError('Upload request failed');
            }
        });
    }
    
    /**
     * 加载现有任务
     */
    loadExistingJobs() {
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pip_get_jobs_table',
                nonce: pip_ajax_vars.nonce
            },
            success: (response) => {
                if (response.success) {
                    jQuery('#pip-jobs-table-container').html(response.data.html);
                    this.updateJobStates();
                    
                    // 检查卡住的任务
                    setTimeout(() => {
                        this.checkStuckJobs();
                    }, 1000);
                }
            }
        });
    }
    
    /**
     * 开始导入任务
     */
    async startImport(jobId) {
        if (this.jobs.has(jobId) && this.jobs.get(jobId).status === 'running') {
            this.showError('Job is already running');
            return;
        }
        
        try {
            // 1. 验证CSV文件
            this.updateJobStatus(jobId, 'Validating CSV...');
            await this.validateCsv(jobId);
            
            // 2. 开始批量导入
            this.updateJobStatus(jobId, 'Starting import...');
            await this.startBatchImport(jobId);
            
            // 3. 开始处理循环
            this.processJob(jobId);
            
        } catch (error) {
            this.showError(`Failed to start import: ${error.message}`);
            this.updateJobStatus(jobId, 'Failed to start');
        }
    }
    
    /**
     * 验证CSV文件
     */
    validateCsv(jobId) {
        return new Promise((resolve, reject) => {
            jQuery.ajax({
                url: pip_ajax_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pip_validate_csv',
                    job_id: jobId,
                    nonce: pip_ajax_vars.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const jobData = response.data.data;
                        this.jobs.set(jobId, {
                            id: jobId,
                            totalRows: jobData.total_rows,
                            processedRows: 0,
                            status: 'validated',
                            errors: []
                        });
                        resolve(jobData);
                    } else {
                        reject(new Error(response.data.message));
                    }
                },
                error: () => {
                    reject(new Error('Validation request failed'));
                }
            });
        });
    }
    
    /**
     * 开始批量导入
     */
    startBatchImport(jobId) {
        return new Promise((resolve, reject) => {
            jQuery.ajax({
                url: pip_ajax_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pip_start_batch_import',
                    job_id: jobId,
                    nonce: pip_ajax_vars.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const job = this.jobs.get(jobId);
                        job.status = 'running';
                        job.startTime = new Date();
                        this.jobs.set(jobId, job);
                        resolve();
                    } else {
                        reject(new Error(response.data.message));
                    }
                },
                error: () => {
                    reject(new Error('Start import request failed'));
                }
            });
        });
    }
    
    /**
     * 处理任务（主循环）
     */
    async processJob(jobId) {
        const job = this.jobs.get(jobId);
        if (!job || job.status !== 'running') return;
        
        let currentRow = job.processedRows || 0;
        let retryCount = 0;
        
        while (currentRow < job.totalRows && job.status === 'running') {
            try {
                // 处理一个批次
                const result = await this.processBatch(jobId, currentRow, this.batchSize);
                
                if (result.processed > 0) {
                    currentRow += result.processed;
                    job.processedRows = currentRow;
                    
                    // 更新UI
                    this.updateJobProgress(jobId, currentRow, job.totalRows);
                    
                    // 记录错误
                    if (result.errors.length > 0) {
                        job.errors.push(...result.errors);
                    }
                    
                    retryCount = 0; // 重置重试计数
                }
                
                // 检查是否完成
                if (result.is_complete || currentRow >= job.totalRows) {
                    job.status = 'completed';
                    job.endTime = new Date();
                    this.updateJobStatus(jobId, 'Completed');
                    this.showSuccess(`Job #${jobId} completed successfully`);
                    break;
                }
                
                // 延迟处理下一批次
                await this.delay(this.processDelay);
                
            } catch (error) {
                retryCount++;
                
                if (retryCount >= this.maxRetries) {
                    job.status = 'failed';
                    this.updateJobStatus(jobId, 'Failed after retries');
                    this.showError(`Job #${jobId} failed: ${error.message}`);
                    break;
                }
                
                // 指数退避重试
                await this.delay(Math.pow(2, retryCount) * 1000);
            }
        }
        
        this.jobs.set(jobId, job);
    }
    
    /**
     * 处理单个批次
     */
    processBatch(jobId, startRow, batchSize) {
        return new Promise((resolve, reject) => {
            jQuery.ajax({
                url: pip_ajax_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pip_process_batch',
                    job_id: jobId,
                    start_row: startRow,
                    batch_size: batchSize,
                    nonce: pip_ajax_vars.nonce
                },
                timeout: 120000, // 2分钟超时
                success: (response) => {
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        reject(new Error(response.data.message));
                    }
                },
                error: (xhr, status, error) => {
                    reject(new Error(`Batch processing failed: ${error}`));
                }
            });
        });
    }
    
    /**
     * 暂停导入
     */
    pauseImport(jobId) {
        const job = this.jobs.get(jobId);
        if (job) {
            job.status = 'paused';
        }
        
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pip_pause_import',
                job_id: jobId,
                nonce: pip_ajax_vars.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.updateJobStatus(jobId, 'Paused');
                    this.showSuccess('Import paused');
                }
            }
        });
    }
    
    /**
     * 恢复导入
     */
    resumeImport(jobId) {
        const job = this.jobs.get(jobId);
        if (job) {
            job.status = 'running';
            this.processJob(jobId); // 重新开始处理循环
        }
        
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pip_resume_import',
                job_id: jobId,
                nonce: pip_ajax_vars.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.updateJobStatus(jobId, 'Running');
                    this.showSuccess('Import resumed');
                }
            }
        });
    }
    
    /**
     * 取消导入
     */
    cancelImport(jobId) {
        const job = this.jobs.get(jobId);
        if (job) {
            job.status = 'cancelled';
        }
        
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pip_cancel_import',
                job_id: jobId,
                nonce: pip_ajax_vars.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.updateJobStatus(jobId, 'Cancelled');
                    this.showSuccess('Import cancelled');
                }
            }
        });
    }
    
    /**
     * 启用后台处理模式
     */
    enableBackgroundMode(jobId) {
        const message = window.pip_cron_disabled ? 
            'Switch to background processing? (wp-cron is disabled, will use async mode)' :
            'Switch to background processing? You can close the browser after this.';
            
        if (!confirm(message)) {
            return;
        }
        
        const job = this.jobs.get(jobId);
        if (job) {
            job.status = 'background_processing';
        }
        
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pip_enable_background_mode',
                job_id: jobId,
                mode: 'auto', // 让服务器自动选择最佳模式
                nonce: pip_ajax_vars.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.updateJobStatus(jobId, 'Background Processing');
                    this.showSuccess(response.data.message);
                    
                    // 显示后台处理提示
                    this.showBackgroundNotice(jobId, response.data);
                } else {
                    this.showError(response.data.message || 'Failed to enable background mode');
                }
            },
            error: () => {
                this.showError('Failed to enable background processing');
            }
        });
    }
    
    /**
     * 显示后台处理通知
     */
    showBackgroundNotice(jobId, data) {
        const cronStatus = data.cron_available ? 
            'WordPress Cron is available' : 
            'WordPress Cron is disabled - using async mode';
            
        const notice = jQuery(`
            <div class="notice notice-info pip-background-notice" style="position: relative;">
                <p>
                    <strong>Job #${jobId} is now running in background mode (${data.mode}).</strong><br>
                    Status: ${cronStatus}<br>
                    You can safely close this browser tab. The import will continue on the server.<br>
                    <a href="#" onclick="location.reload()">Refresh page</a> to check progress.
                </p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `);
        
        jQuery('.wrap h1').after(notice);
        
        // 点击关闭
        notice.find('.notice-dismiss').on('click', () => {
            notice.fadeOut(() => notice.remove());
        });
    }
    
    /**
     * 开始所有任务
     */
    startAllJobs() {
        pipLog('startAllJobs() called');
        
        // 检查是否有任务表格
        const jobTable = jQuery('.pip-jobs-table');
        if (jobTable.length === 0) {
            pipError('No job table found on page');
            this.showError('No job table found. Please refresh the page.');
            return;
        }
        
        pipLog('Job table found, scanning for jobs...');
        
        // 直接从DOM读取任务状态，不依赖内存中的jobs Map
        const startableJobs = [];
        const allJobs = [];
        
        jQuery('.job-row').each((index, element) => {
            const $row = jQuery(element);
            const jobId = $row.data('job-id');
            const statusElement = $row.find('.job-status');
            const status = statusElement.text().toLowerCase().trim();
            
            allJobs.push({ id: jobId, status: status });
            pipLog(`Found job ${jobId} with status: "${status}"`);
            
            // 检查可启动的状态
            if (['pending', 'uploaded', 'validated', 'paused'].includes(status)) {
                startableJobs.push(jobId);
                pipLog(`Job ${jobId} is startable`);
            }
        });
        
        pipLog('All jobs found:', allJobs);
        pipLog('Startable jobs:', startableJobs);
        
        if (startableJobs.length === 0) {
            const message = allJobs.length === 0 ? 
                'No jobs found on the page.' : 
                `Found ${allJobs.length} jobs, but none are in a startable state (pending, uploaded, validated, or paused).`;
            
            pipError(message);
            this.showError(message);
            return;
        }
        
        pipLog(`Starting ${startableJobs.length} jobs...`);
        this.showProgress(`Starting ${startableJobs.length} jobs...`);
        
        // 逐个启动任务，避免并发冲突
        let successCount = 0;
        let errorCount = 0;
        
        startableJobs.forEach((jobId, index) => {
            setTimeout(() => {
                pipLog(`Starting job ${jobId} (${index + 1}/${startableJobs.length})`);
                
                // 检查任务是否仍然存在
                const jobRow = jQuery(`#job-${jobId}`);
                if (jobRow.length === 0) {
                    pipError(`Job ${jobId} row not found in DOM`);
                    errorCount++;
                    return;
                }
                
                try {
                    this.startImport(jobId).then(() => {
                        successCount++;
                        pipLog(`Job ${jobId} started successfully`);
                        
                        // 如果是最后一个任务，显示总结
                        if (index === startableJobs.length - 1) {
                            setTimeout(() => {
                                this.showSuccess(`Batch start completed: ${successCount} started, ${errorCount} errors`);
                            }, 1000);
                        }
                    }).catch((error) => {
                        errorCount++;
                        pipError(`Failed to start job ${jobId}:`, error);
                    });
                } catch (error) {
                    errorCount++;
                    pipError(`Exception starting job ${jobId}:`, error);
                }
            }, index * 2000); // 每个任务间隔2秒启动
        });
    }
    
    /**
     * 暂停所有任务
     */
    pauseAllJobs() {
        console.log('Pausing all jobs...');
        
        // 直接从DOM读取任务状态
        const runningJobs = [];
        jQuery('.job-row').each((index, element) => {
            const jobId = jQuery(element).data('job-id');
            const statusElement = jQuery(element).find('.job-status');
            const status = statusElement.text().toLowerCase().trim();
            
            if (status === 'running') {
                runningJobs.push(jobId);
            }
        });
        
        console.log('Running jobs to pause:', runningJobs);
        
        if (runningJobs.length === 0) {
            this.showError('No running jobs to pause');
            return;
        }
        
        this.showProgress(`Pausing ${runningJobs.length} jobs...`);
        runningJobs.forEach(jobId => this.pauseImport(jobId));
    }
    
    /**
     * 更新任务状态
     */
    updateJobStatus(jobId, status) {
        const statusCell = jQuery(`#job-${jobId} .job-status`);
        if (statusCell.length) {
            statusCell.text(status);
        }
    }
    
    /**
     * 更新任务进度
     */
    updateJobProgress(jobId, processed, total) {
        const progressCell = jQuery(`#job-${jobId} .job-progress`);
        const progressBar = jQuery(`#job-${jobId} .progress-bar`);
        
        if (progressCell.length) {
            const percentage = Math.round((processed / total) * 100);
            progressCell.text(`${processed} / ${total} (${percentage}%)`);
        }
        
        if (progressBar.length) {
            const percentage = (processed / total) * 100;
            progressBar.css('width', `${percentage}%`);
        }
    }
    
    /**
     * 更新任务状态
     */
    updateJobStates() {
        // 扫描页面上的任务，更新内存中的状态
        jQuery('.job-row').each((index, element) => {
            const jobId = jQuery(element).data('job-id');
            const status = jQuery(element).find('.job-status').text().toLowerCase();
            const processed = parseInt(jQuery(element).data('processed') || 0);
            const total = parseInt(jQuery(element).data('total') || 0);
            
            if (jobId) {
                this.jobs.set(jobId, {
                    id: jobId,
                    status: status,
                    processedRows: processed,
                    totalRows: total,
                    errors: []
                });
            }
        });
        
        // 更新统计数据
        this.updateStatistics();
    }
    
    /**
     * 更新统计数据
     */
    updateStatistics() {
        const stats = {
            pending: 0,
            running: 0,
            completed: 0,
            failed: 0
        };
        
        jQuery('.job-row').each((index, element) => {
            const status = jQuery(element).find('.job-status').text().toLowerCase().trim();
            
            if (['pending', 'uploaded', 'validated'].includes(status)) {
                stats.pending++;
            } else if (['running', 'background_processing'].includes(status)) {
                stats.running++;
            } else if (status === 'completed') {
                stats.completed++;
            } else if (status === 'failed') {
                stats.failed++;
            }
        });
        
        // 更新UI中的统计数字
        jQuery('.pip-stat.pending .pip-stat-count').text(stats.pending);
        jQuery('.pip-stat.running .pip-stat-count').text(stats.running);
        jQuery('.pip-stat.completed .pip-stat-count').text(stats.completed);
        jQuery('.pip-stat.failed .pip-stat-count').text(stats.failed);
    }
    
    /**
     * 自动刷新状态
     */
    startAutoRefresh() {
        setInterval(() => {
            // 只有在有运行中的任务时才刷新
            const hasRunningJobs = Array.from(this.jobs.values())
                .some(job => job.status === 'running');
            
            if (hasRunningJobs || !document.hidden) {
                this.loadExistingJobs();
            }
        }, 5000);
    }
    
    /**
     * 工具方法
     */
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    
    showProgress(message) {
        this.showNotice(message, 'info');
    }
    
    showSuccess(message) {
        this.showNotice(message, 'success');
    }
    
    showError(message) {
        this.showNotice(message, 'error');
    }
    
    showNotice(message, type = 'info') {
        const notice = jQuery(`
            <div class="notice notice-${type} is-dismissible">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `);
        
        jQuery('.wrap h1').after(notice);
        
        // 自动消失
        setTimeout(() => {
            notice.fadeOut(() => notice.remove());
        }, 5000);
        
        // 点击关闭
        notice.find('.notice-dismiss').on('click', () => {
            notice.fadeOut(() => notice.remove());
        });
    }
    
    /**
     * 扫描上传目录
     */
    scanUploadDirectory() {
        this.showProgress('Scanning upload directory for CSV files...');
        
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pip_scan_files',
                nonce: pip_ajax_vars.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.showSuccess(response.data.message);
                    this.loadExistingJobs(); // 刷新任务列表
                    
                    // 显示详细信息
                    if (response.data.new_jobs.length > 0) {
                        console.log('New jobs created:', response.data.new_jobs);
                    }
                    if (response.data.errors.length > 0) {
                        console.log('Scan errors:', response.data.errors);
                    }
                } else {
                    this.showError(response.data.message || 'Failed to scan directory');
                }
            },
            error: () => {
                this.showError('Failed to scan upload directory');
            }
        });
    }
    
    /**
     * 更新批量操作按钮的可见性
     */
    updateBulkActionsVisibility() {
        const checkedBoxes = jQuery('.pip-job-checkbox:checked');
        const bulkActions = jQuery('.pip-bulk-actions');
        const selectedCount = jQuery('.pip-selected-count');
        
        if (checkedBoxes.length > 0) {
            bulkActions.show();
            selectedCount.text(`${checkedBoxes.length} selected`);
            
            // 更新全选框状态
            const totalBoxes = jQuery('.pip-job-checkbox').length;
            const allChecked = checkedBoxes.length === totalBoxes;
            jQuery('#pip-select-all-jobs').prop('checked', allChecked);
        } else {
            bulkActions.hide();
            jQuery('#pip-select-all-jobs').prop('checked', false);
        }
    }
    
    /**
     * 处理批量操作
     */
    handleBulkAction(action) {
        const checkedBoxes = jQuery('.pip-job-checkbox:checked');
        if (checkedBoxes.length === 0) {
            this.showError('No jobs selected');
            return;
        }
        
        const jobIds = [];
        checkedBoxes.each((index, element) => {
            jobIds.push(jQuery(element).val());
        });
        
        const actionNames = {
            'start': 'start',
            'pause': 'pause', 
            'cancel': 'cancel',
            'delete': 'delete'
        };
        
        const actionName = actionNames[action];
        if (!actionName) {
            this.showError('Invalid action');
            return;
        }
        
        // 确认删除操作
        if (action === 'delete') {
            if (!confirm(`Are you sure you want to delete ${jobIds.length} selected jobs? This action cannot be undone.`)) {
                return;
            }
        }
        
        this.showProgress(`${actionName.charAt(0).toUpperCase() + actionName.slice(1)}ing ${jobIds.length} jobs...`);
        
        // 执行批量操作
        jobIds.forEach((jobId, index) => {
            setTimeout(() => {
                switch (action) {
                    case 'start':
                        this.startImport(jobId);
                        break;
                    case 'pause':
                        this.pauseImport(jobId);
                        break;
                    case 'cancel':
                        this.cancelImport(jobId);
                        break;
                    case 'delete':
                        this.deleteJob(jobId);
                        break;
                }
            }, index * 500); // 每个操作间隔500ms
        });
        
        // 清除选择
        setTimeout(() => {
            jQuery('.pip-job-checkbox').prop('checked', false);
            this.updateBulkActionsVisibility();
        }, jobIds.length * 500 + 1000);
    }
    
    /**
     * 删除任务
     */
    deleteJob(jobId) {
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pip_job_action',
                job_action: 'delete',
                job_id: jobId,
                nonce: pip_ajax_vars.nonce
            },
            success: (response) => {
                if (response.success) {
                    // 从DOM中移除任务行
                    jQuery(`#job-${jobId}`).fadeOut(() => {
                        jQuery(`#job-${jobId}`).remove();
                        this.updateBulkActionsVisibility();
                    });
                } else {
                    this.showError(`Failed to delete job ${jobId}: ${response.data.message}`);
                }
            },
            error: () => {
                this.showError(`Failed to delete job ${jobId}`);
            }
        });
    }
    
    /**
     * 重置卡住的任务
     */
    resetStuckJob(jobId) {
        pipLog(`Resetting stuck job ${jobId}`);
        
        jQuery.ajax({
            url: pip_ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'pip_reset_job',
                job_id: jobId,
                nonce: pip_ajax_vars.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.showSuccess(`Job #${jobId} has been reset`);
                    this.loadExistingJobs(); // 刷新任务列表
                } else {
                    this.showError(`Failed to reset job #${jobId}: ${response.data.message}`);
                }
            },
            error: () => {
                this.showError(`Failed to reset job #${jobId}`);
            }
        });
    }
    
    /**
     * 检查并处理卡住的任务
     */
    checkStuckJobs() {
        pipLog('Checking for stuck jobs...');
        
        jQuery('.job-row').each((index, element) => {
            const $row = jQuery(element);
            const jobId = $row.data('job-id');
            const status = $row.find('.job-status').text().toLowerCase().trim();
            
            // 检查运行中但可能卡住的任务
            if (status === 'running') {
                const startedAt = $row.find('td:nth-child(6)').text().trim();
                if (startedAt && startedAt !== '—') {
                    // 如果任务运行超过10分钟，认为可能卡住了
                    const startTime = new Date(startedAt).getTime();
                    const now = new Date().getTime();
                    const runningTime = (now - startTime) / 1000 / 60; // 分钟
                    
                    if (runningTime > 10) {
                        pipLog(`Job ${jobId} may be stuck (running for ${runningTime.toFixed(1)} minutes)`);
                        
                        // 添加重置按钮
                        const actionsCell = $row.find('td:last-child > div');
                        if (actionsCell.find('.pip-reset-job').length === 0) {
                            const resetButton = jQuery(`
                                <button class="pip-action-button pip-reset-job" data-job-id="${jobId}" title="Reset Stuck Job" style="background: #ff9800; color: white; border-color: #ff9800;">
                                    <span class="dashicons dashicons-update"></span>
                                </button>
                            `);
                            actionsCell.append(resetButton);
                        }
                    }
                }
            }
        });
    }
    
    /**
     * 测试按钮是否存在
     */
    testButtonsExistence() {
        pipLog('Testing button existence...');
        
        const buttons = {
            'Start All Jobs': '#pip-start-all',
            'Pause All Jobs': '#pip-pause-all', 
            'Scan Upload Directory': '#pip-scan-files',
            'Processing Mode Select': '#pip-processing-mode',
            'Batch Size Input': '#pip-batch-size',
            'Delay Input': '#pip-delay',
            'Drop Zone': '#pip-drop-zone',
            'File Input': '#import_csv_files',
            'Jobs Table': '.pip-jobs-table',
            'Select All Checkbox': '#pip-select-all-jobs'
        };
        
        const missing = [];
        const found = [];
        
        Object.entries(buttons).forEach(([name, selector]) => {
            const element = jQuery(selector);
            if (element.length > 0) {
                found.push(name);
                pipLog(`✓ Found: ${name} (${selector})`);
            } else {
                missing.push(name);
                pipError(`✗ Missing: ${name} (${selector})`);
            }
        });
        
        if (missing.length > 0) {
            pipError(`Missing UI elements: ${missing.join(', ')}`);
            this.showError(`Some UI elements are missing: ${missing.join(', ')}`);
        } else {
            pipLog('All UI elements found successfully!');
        }
        
        // 测试AJAX变量
        if (typeof pip_ajax_vars !== 'undefined') {
            pipLog('AJAX variables available:', {
                ajax_url: pip_ajax_vars.ajax_url,
                nonce: pip_ajax_vars.nonce ? 'Present' : 'Missing'
            });
        } else {
            pipError('pip_ajax_vars not available!');
        }
    }
}

// 初始化批处理器 - 改进版
function initPipBatchProcessor() {
    pipLog('Attempting to initialize PipBatchProcessor...');
    
    // 检查必要条件
    if (typeof jQuery === 'undefined') {
        pipError('jQuery not available, retrying in 1 second...');
        setTimeout(initPipBatchProcessor, 1000);
        return;
    }
    
    if (typeof pip_ajax_vars === 'undefined') {
        pipError('pip_ajax_vars not available, retrying in 1 second...');
        setTimeout(initPipBatchProcessor, 1000);
        return;
    }
    
    // 检查页面元素
    if (jQuery('#pip-start-all').length === 0) {
        pipError('Required page elements not found, retrying in 1 second...');
        setTimeout(initPipBatchProcessor, 1000);
        return;
    }
    
    pipLog('All conditions met, initializing PipBatchProcessor');
    window.pipBatchProcessor = new PipBatchProcessor();
}

// 多种初始化方式
if (document.readyState === 'loading') {
    // 文档还在加载
    document.addEventListener('DOMContentLoaded', initPipBatchProcessor);
} else {
    // 文档已经加载完成
    initPipBatchProcessor();
}

// jQuery ready 作为备用
jQuery(document).ready(function() {
    pipLog('jQuery ready fired');
    if (!window.pipBatchProcessor) {
        setTimeout(initPipBatchProcessor, 500);
    }
});

// 窗口加载完成作为最后备用
jQuery(window).on('load', function() {
    pipLog('Window load fired');
    if (!window.pipBatchProcessor) {
        setTimeout(initPipBatchProcessor, 1000);
    }
}); 