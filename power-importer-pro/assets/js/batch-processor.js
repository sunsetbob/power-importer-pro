/**
 * Power Importer Pro - Batch Processor (Refactored for JSON table)
 */

const PIP_DEBUG = true; // Or get from localized var if needed

function pipLog(message, data = null) {
    if (PIP_DEBUG) {
        console.log('[PIP Debug]', message, data || '');
    }
}

function pipError(message, error = null) {
    console.error('[PIP Error]', message, error || '');
}

(function($) {
    'use strict';

    if (typeof pip_ajax_vars === 'undefined') {
        pipError('pip_ajax_vars is not defined! AJAX functionality will not work.');
        // Provide a fallback to prevent further errors if script runs partially
        window.pip_ajax_vars = {
            ajax_url: '/wp-admin/admin-ajax.php',
            nonce: 'missing_nonce',
            get_jobs_nonce: 'missing_get_jobs_nonce',
            admin_url: '/wp-admin/',
            is_pip_page: false,
            strings: {}
        };
    }

    class PipBatchProcessor {
        constructor() {
            pipLog('Initializing PipBatchProcessor (JSON refactor)...');
            this.jobs = new Map(); // Stores job data, keyed by job_id
            this.batchSize = parseInt(localStorage.getItem('pip_batch_size')) || 10;
            this.processDelay = parseInt(localStorage.getItem('pip_process_delay')) || 1000;
            this.maxRetries = 3;
            this.autoRefreshInterval = null;

            // Ensure this only runs on the plugin's admin page
            if (pip_ajax_vars.is_pip_page) {
                this.init();
            } else {
                pipLog('Not on Power Importer Pro page, PipBatchProcessor will not fully initialize.');
            }
        }

        init() {
            pipLog('PipBatchProcessor full init on plugin page.');
            $('#pip-batch-size').val(this.batchSize);
            $('#pip-delay').val(this.processDelay);
            this.bindEvents();
            this.loadExistingJobs();
            this.startAutoRefresh();
        }

        bindEvents() {
            // File Upload
            $(document).on('change', '#import_csv_files', (e) => this.handleFileSelection(e));
            this.initDragAndDrop();

            // Job Actions (delegated)
            $(document).on('click', '.pip-job-action', (e) => {
                e.preventDefault();
                const $button = $(e.currentTarget);
                const jobId = $button.data('job-id');
                const action = $button.data('action');
                this.handleJobAction(jobId, action, $button);
            });

            // Global Controls
            $('#pip-start-all').on('click', () => this.startAllJobs());
            $('#pip-pause-all').on('click', () => this.pauseAllJobs());
            $('#pip-scan-files').on('click', () => this.scanUploadDirectory());

            // Bulk Actions
            $('#pip-select-all-jobs').on('change', (e) => {
                $('.pip-job-checkbox').prop('checked', $(e.target).is(':checked'));
                this.updateBulkActionsVisibility();
            });
            $(document).on('change', '.pip-job-checkbox', () => this.updateBulkActionsVisibility());
            $('.pip-bulk-action-btn').on('click', (e) => {
                const action = $(e.currentTarget).data('action');
                this.handleBulkAction(action);
            });
            
            // Settings persistence
            $('#pip-batch-size').on('change', (e) => {
                this.batchSize = parseInt($(e.target).val()) || 10;
                localStorage.setItem('pip_batch_size', this.batchSize);
                this.showNotice(`Batch size set to ${this.batchSize}`, 'info', 2000);
            });
            $('#pip-delay').on('change', (e) => {
                this.processDelay = parseInt($(e.target).val()) || 1000;
                localStorage.setItem('pip_process_delay', this.processDelay);
                this.showNotice(`Processing delay set to ${this.processDelay}ms`, 'info', 2000);
            });
        }
        
        initDragAndDrop() {
            const dropZone = $('#pip-drop-zone');
            if (!dropZone.length) return;

            $(document).on('dragover dragenter', (e) => { e.preventDefault(); e.stopPropagation(); });
            dropZone.on('dragenter dragover', () => dropZone.addClass('drag-over'));
            dropZone.on('dragleave dragend drop', () => dropZone.removeClass('drag-over'));
            dropZone.on('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) this.handleDroppedFiles(files);
            });
            dropZone.on('click', () => $('#import_csv_files').click());
        }

        handleDroppedFiles(files) {
            const validFiles = Array.from(files).filter(file => file.type === 'text/csv' || file.name.toLowerCase().endsWith('.csv'));
            const invalidFileNames = Array.from(files).filter(file => !validFiles.includes(file)).map(f => f.name);

            if (invalidFileNames.length > 0) {
                this.showError(`Invalid file types: ${invalidFileNames.join(', ')}. Only CSV/TXT files are allowed.`);
            }
            if (validFiles.length === 0) return;

            const formData = new FormData();
            formData.append('action', 'pip_upload_files');
            formData.append('nonce', pip_ajax_vars.nonce);
            validFiles.forEach(file => formData.append('import_csv_files[]', file));
            this.uploadFiles(formData, validFiles.length);
        }

        handleFileSelection(e) {
            const files = e.target.files;
            if (files.length === 0) return;
            const formData = new FormData();
            formData.append('action', 'pip_upload_files');
            formData.append('nonce', pip_ajax_vars.nonce);
            Array.from(files).forEach(file => formData.append('import_csv_files[]', file));
            this.uploadFiles(formData, files.length);
            $(e.target).val(''); // Clear file input
        }

        uploadFiles(formData, fileCount) {
            this.showProgress(`Uploading ${fileCount} file(s)...`);
            $.ajax({
                url: pip_ajax_vars.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    if (response.success) {
                        let message = response.data.message || `${fileCount} file(s) processed for upload.`;
                        if(response.data.errors && response.data.errors.length > 0){
                            message += ` Some files had errors: ${response.data.errors.join(', ')}`;
                            this.showError(message, 'warning'); // Show as warning if some succeed
                        } else {
                            this.showSuccess(message);
                        }
                        this.loadExistingJobs(); 
                    } else {
                        this.showError(response.data.message || 'Upload failed.');
                    }
                },
                error: (xhr) => this.showError(`Upload request failed: ${xhr.statusText || 'Unknown error'}`)
            });
        }

        loadExistingJobs() {
            if (this.isLoadingJobs) return;
            this.isLoadingJobs = true;

            $.ajax({
                url: pip_ajax_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'pip_get_jobs_table', // PHP action name
                    nonce: pip_ajax_vars.get_jobs_nonce // Use specific nonce if defined
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success && response.data && Array.isArray(response.data.jobs)) {
                        this.renderJobsTable(response.data.jobs);
                        this.updateJobStates(response.data.jobs); // Update internal state and stats
                    } else {
                        this.showError('Failed to load jobs or invalid data format received.');
                        $('#pip-jobs-table-body').html('<tr><td colspan="8">Error loading jobs.</td></tr>');
                    }
                },
                error: (xhr) => {
                    this.showError(`Failed to fetch jobs list: ${xhr.statusText || 'Unknown error'}`);
                     $('#pip-jobs-table-body').html('<tr><td colspan="8">Error loading jobs.</td></tr>');
                },
                complete: () => {
                    this.isLoadingJobs = false;
                }
            });
        }

        renderJobsTable(jobsData) {
            const $tableBody = $('#pip-jobs-table-body');
            $tableBody.empty(); // Clear existing rows

            if (jobsData.length === 0) {
                $('#pip-jobs-empty-state').show();
                return;
            }
            $('#pip-jobs-empty-state').hide();

            jobsData.forEach(job => {
                const logLink = `${pip_ajax_vars.admin_url}edit.php?post_type=product&page=power-importer-pro&view_log=${job.id}`;
                let progressHtml = 'N/A';
                if (job.total_rows > 0) {
                    const percentage = Math.round((job.processed_rows / job.total_rows) * 100);
                    progressHtml = `
                        <div class="pip-job-progress-text job-progress">${job.processed_rows} / ${job.total_rows} (${percentage}%)</div>
                        <div class="pip-job-progress-bar"><div class="pip-job-progress-fill progress-bar" style="width: ${percentage}%;"></div></div>
                    `;
                } else if (job.status === 'validated' || job.status === 'pending' || job.status === 'uploaded') {
                     progressHtml = 'Awaiting processing...';
                }

                const rowHtml = `
                    <tr class="job-row" id="job-${job.id}" data-job-id="${job.id}" data-status="${job.status}">
                        <td style="padding-left: 10px;"><input type="checkbox" class="pip-job-checkbox" value="${job.id}"></td>
                        <td>#${job.id}</td>
                        <td><strong>${job.file_name}</strong><br><small>${job.total_rows > 0 ? job.total_rows + ' rows' : (job.status === 'validated' ? '0 rows (empty?)' : 'Not validated')}</small></td>
                        <td><span class="pip-status-badge pip-status-${job.status} job-status">${this.getJobStatusDisplay(job.status)}</span></td>
                        <td class="pip-job-progress">${progressHtml}</td>
                        <td>${job.started_at_formatted || '—'}</td>
                        <td>${job.duration_formatted || '—'}</td>
                        <td><div class="pip-job-actions-cell">${this.getActionsHtml(job)}</div></td>
                    </tr>
                `;
                $tableBody.append(rowHtml);
            });
        }

        getJobStatusDisplay(statusKey) {
            return pip_ajax_vars.strings[`status_${statusKey}`] || pip_ajax_vars.strings['status_unknown'] || statusKey;
        }

        getActionsHtml(job) {
            const logLink = `${pip_ajax_vars.admin_url}edit.php?post_type=product&page=power-importer-pro&view_log=${job.id}`;
            let actions = `<a href="${logLink}" class="pip-action-button" title="${pip_ajax_vars.strings.view_log_label}"><span class="dashicons dashicons-visibility"></span></a>`;

            const canStart = ['pending', 'validated', 'paused', 'uploaded'];
            const canPause = ['running_ajax'];
            const canResume = ['paused'];
            const canCancel = ['pending', 'validated', 'running_ajax', 'paused', 'queued_async', 'running_async'];
            const canBackground = ['pending', 'validated', 'paused', 'uploaded', 'running_ajax']; // Can switch running AJAX to background
            const canDelete = ['completed', 'failed', 'cancelled', 'pending', 'validated', 'uploaded'];
            const canReset = ['running_ajax', 'running_async', 'failed', 'queued_async']; // For stuck or failed jobs

            if (canStart.includes(job.status)) {
                actions += `<button class="pip-action-button primary pip-job-action" data-job-id="${job.id}" data-action="start" title="${pip_ajax_vars.strings.start_label}"><span class="dashicons dashicons-controls-play"></span></button>`;
            }
            if (canPause.includes(job.status)) {
                actions += `<button class="pip-action-button pip-job-action" data-job-id="${job.id}" data-action="pause" title="${pip_ajax_vars.strings.pause_label}"><span class="dashicons dashicons-controls-pause"></span></button>`;
            }
            if (canResume.includes(job.status)) {
                actions += `<button class="pip-action-button primary pip-job-action" data-job-id="${job.id}" data-action="resume" title="${pip_ajax_vars.strings.resume_label}"><span class="dashicons dashicons-controls-play"></span></button>`;
            }
             if (canBackground.includes(job.status)) {
                actions += `<button class="pip-action-button pip-job-action" data-job-id="${job.id}" data-action="background" title="${pip_ajax_vars.strings.background_label}" style="background: #28a745; color: white;"><span class="dashicons dashicons-cloud"></span></button>`;
            }
            if (canCancel.includes(job.status)) {
                actions += `<button class="pip-action-button pip-job-action" data-job-id="${job.id}" data-action="cancel" title="${pip_ajax_vars.strings.cancel_label}" style="color: #d9534f;"><span class="dashicons dashicons-no"></span></button>`;
            }
            if (canReset.includes(job.status) || (job.status === 'failed' && job.error_message && job.error_message.includes('stuck'))) { // Example for specific reset condition
                 actions += `<button class="pip-action-button pip-job-action" data-job-id="${job.id}" data-action="reset" title="${pip_ajax_vars.strings.reset_label}" style="background: #ff9800; color: white;"><span class="dashicons dashicons-update"></span></button>`;
            }
            if (canDelete.includes(job.status)) {
                actions += `<button class="pip-action-button pip-job-action" data-job-id="${job.id}" data-action="delete" title="${pip_ajax_vars.strings.delete_label}" style="color: #e74c3c;"><span class="dashicons dashicons-trash"></span></button>`;
            }
            return actions;
        }
        
        handleJobAction(jobId, action, $button) {
            pipLog(`Handling action '${action}' for job ID ${jobId}`);
            switch (action) {
                case 'start': this.startImport(jobId); break;
                case 'pause': this.pauseImport(jobId); break;
                case 'resume': this.resumeImport(jobId); break;
                case 'cancel': 
                    if (confirm(pip_ajax_vars.strings.confirm_cancel)) this.cancelImport(jobId); 
                    break;
                case 'background': this.enableBackgroundMode(jobId); break;
                case 'delete': 
                    if (confirm(pip_ajax_vars.strings.confirm_delete)) this.deleteJob(jobId); 
                    break;
                case 'reset':
                    if (confirm(pip_ajax_vars.strings.confirm_reset)) this.resetStuckJob(jobId);
                    break;
                default: pipError('Unknown job action:', action);
            }
        }

        async startImport(jobId) {
            const job = this.jobs.get(jobId);
            if (job && (job.status === 'running_ajax' || job.status === 'running_async' || job.status === 'queued_async')) {
                this.showError('Job is already running or queued for background processing.');
                return;
            }

            try {
                this.updateJobStatusDisplay(jobId, 'Validating CSV...');
                const validationData = await this.validateCsv(jobId);
                // this.jobs map is updated within validateCsv's promise on success

                this.updateJobStatusDisplay(jobId, 'Starting AJAX import...');
                await this.startBatchImport(jobId); 
                // this.jobs map is updated within startBatchImport's promise
                
                this.processJob(jobId); // Start the AJAX batching loop

            } catch (error) {
                this.showError(`Failed to start import for job #${jobId}: ${error.message}`);
                this.updateJobStatusDisplay(jobId, 'failed'); // Update UI to failed
                // Ensure the job object in the map also reflects failure if possible
                const jobObj = this.jobs.get(jobId);
                if(jobObj) {
                    jobObj.status = 'failed';
                    this.updateStatistics(); // Refresh stats
                }
            }
        }

        validateCsv(jobId) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: pip_ajax_vars.ajax_url, type: 'POST',
                    data: { action: 'pip_validate_csv', job_id: jobId, nonce: pip_ajax_vars.nonce },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) {
                            const jobData = response.data.data;
                            const currentJob = this.jobs.get(jobId) || {};
                            this.jobs.set(jobId, {
                                ...currentJob, id: jobId, 
                                totalRows: jobData.total_rows, 
                                processedRows: 0, // Reset for new import session
                                status: 'validated', 
                                errors: []
                            });
                            this.updateJobRow(jobId); // Update specific row in UI
                            resolve(jobData);
                        } else {
                            reject(new Error(response.data.message || 'Validation failed on server.'));
                        }
                    },
                    error: (xhr) => reject(new Error(`Validation AJAX request failed: ${xhr.statusText || 'Unknown error'}`))
                });
            });
        }

        startBatchImport(jobId) { // This is for foreground AJAX
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: pip_ajax_vars.ajax_url, type: 'POST',
                    data: { action: 'pip_start_batch_import', job_id: jobId, nonce: pip_ajax_vars.nonce },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) {
                            const job = this.jobs.get(jobId);
                            if (job) {
                                job.status = 'running_ajax'; // Ensure correct status for AJAX mode
                                job.startTime = new Date();
                                this.updateJobRow(jobId);
                            }
                            resolve();
                        } else {
                            reject(new Error(response.data.message || 'Failed to start batch import on server.'));
                        }
                    },
                    error: (xhr) => reject(new Error(`Start batch import AJAX request failed: ${xhr.statusText || 'Unknown error'}`))
                });
            });
        }

        async processJob(jobId) { // Foreground AJAX processing loop
            const job = this.jobs.get(jobId);
            if (!job || job.status !== 'running_ajax') {
                pipLog(`processJob for #${jobId} halted. Status: ${job ? job.status : 'N/A'}`);
                return;
            }

            let retryCount = 0;
            this.updateJobRow(jobId);

            while (job.processedRows < job.totalRows && job.status === 'running_ajax') {
                try {
                    const result = await this.processBatchRequest(jobId, job.processedRows, this.batchSize);
                    
                    if (result.processed > 0) {
                        job.processedRows += result.processed;
                        if (result.errors && result.errors.length > 0) {
                            job.errors = job.errors.concat(result.errors);
                            // Optionally show these errors in UI or log, for now they are in job object
                        }
                        retryCount = 0; 
                    }
                    this.updateJobRow(jobId); // Update UI after each batch

                    if (result.is_complete || job.processedRows >= job.totalRows) {
                        job.status = 'completed';
                        job.endTime = new Date();
                        this.showSuccess(`Job #${jobId} (AJAX) completed successfully.`);
                        this.updateJobRow(jobId);
                        this.updateStatistics();
                        break;
                    }
                    await this.delay(this.processDelay);
                } catch (error) {
                    retryCount++;
                    this.showError(`Error processing batch for job #${jobId}: ${error.message}. Retry ${retryCount}/${this.maxRetries}.`);
                    if (retryCount >= this.maxRetries) {
                        job.status = 'failed';
                        this.showError(`Job #${jobId} failed after ${this.maxRetries} retries.`);
                        this.updateJobRow(jobId);
                        this.updateStatistics();
                        break;
                    }
                    await this.delay(Math.pow(2, retryCount) * 1000); // Exponential backoff
                }
            }
            // Final update in case loop exited due to status change (e.g. paused)
            this.updateJobRow(jobId);
            this.updateStatistics();
        }

        processBatchRequest(jobId, startRow, currentBatchSize) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: pip_ajax_vars.ajax_url, type: 'POST',
                    data: {
                        action: 'pip_process_batch',
                        job_id: jobId,
                        start_row: startRow, // This is the number of rows already processed
                        batch_size: currentBatchSize,
                        nonce: pip_ajax_vars.nonce
                    },
                    dataType: 'json',
                    timeout: 120000, // 2 minutes timeout for a batch
                    success: (response) => {
                        if (response.success) {
                            resolve(response.data); // Expects: { processed: count, errors: [], total_processed_so_far: count, is_complete: bool, next_start_row: count }
                        } else {
                            reject(new Error(response.data.message || 'Batch processing failed on server.'));
                        }
                    },
                    error: (xhr, status, errorThrown) => {
                        let errorMsg = `Batch AJAX request failed: ${status || 'Unknown error'}`;
                        if (errorThrown) errorMsg += ` - ${errorThrown}`;
                        reject(new Error(errorMsg));
                    }
                });
            });
        }

        pauseImport(jobId) {
            const job = this.jobs.get(jobId);
            if (job && job.status === 'running_ajax') {
                job.status = 'paused'; // Update local state immediately
                this.updateJobRow(jobId);
                this.updateStatistics();

                $.ajax({
                    url: pip_ajax_vars.ajax_url, type: 'POST',
                    data: { action: 'pip_pause_import', job_id: jobId, nonce: pip_ajax_vars.nonce },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) this.showSuccess(`Job #${jobId} paused.`);
                        else this.showError(response.data.message || `Failed to confirm pause for job #${jobId}.`);
                    },
                    error: () => this.showError(`Pause request failed for job #${jobId}.`)
                });
            } else {
                this.showError('Job cannot be paused. Not currently running via AJAX.');
            }
        }

        resumeImport(jobId) {
            const job = this.jobs.get(jobId);
            if (job && job.status === 'paused') {
                job.status = 'running_ajax'; // Update local state
                this.updateJobRow(jobId);
                 this.updateStatistics();
                
                $.ajax({ // Inform server (optional, as client drives AJAX processing)
                    url: pip_ajax_vars.ajax_url, type: 'POST',
                    data: { action: 'pip_resume_import', job_id: jobId, nonce: pip_ajax_vars.nonce },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) this.showSuccess(`Job #${jobId} resumed.`);
                        else this.showError(response.data.message || `Failed to confirm resume for job #${jobId}.`);
                    },
                    error: () => this.showError(`Resume request failed for job #${jobId}.`)
                });
                this.processJob(jobId); // Restart client-side processing loop
            } else {
                 this.showError('Job cannot be resumed. Not currently paused.');
            }
        }

        cancelImport(jobId) {
            $.ajax({
                url: pip_ajax_vars.ajax_url, type: 'POST',
                data: { action: 'pip_cancel_import', job_id: jobId, nonce: pip_ajax_vars.nonce },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        const job = this.jobs.get(jobId);
                        if (job) {
                            job.status = 'cancelled'; // Update local status
                            this.updateJobRow(jobId);
                            this.updateStatistics();
                        }
                        this.showSuccess(`Job #${jobId} cancelled.`);
                    } else {
                        this.showError(response.data.message || `Failed to cancel job #${jobId}.`);
                    }
                },
                error: () => this.showError(`Cancel request failed for job #${jobId}.`)
            });
        }

        enableBackgroundMode(jobId) {
            const job = this.jobs.get(jobId);
            if (job && (job.status === 'running_async' || job.status === 'queued_async')){
                this.showError('Job is already in background mode or queued.');
                return;
            }

            // Confirmation message now comes from localized strings
            if (!confirm(pip_ajax_vars.strings.confirm_background_switch || 'Switch to background processing? You can close the browser after this.')) {
                return;
            }
            
            this.updateJobStatusDisplay(jobId, 'queued_async'); // Optimistic UI update

            $.ajax({
                url: pip_ajax_vars.ajax_url, type: 'POST',
                data: { action: 'pip_enable_background_mode', job_id: jobId, nonce: pip_ajax_vars.nonce }, // Mode is determined server-side now
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        const job = this.jobs.get(jobId);
                        if(job) {
                            job.status = response.data.status || 'queued_async';
                            this.updateJobRow(jobId);
                            this.updateStatistics();
                        }
                        this.showSuccess(response.data.message || `Job #${jobId} switched to background mode.`);
                        // this.showBackgroundNotice(jobId, response.data); // Backend no longer sends cron_available
                    } else {
                        this.showError(response.data.message || `Failed to enable background mode for job #${jobId}.`);
                        this.updateJobRow(jobId); // Re-render to show actual status from server if different
                    }
                },
                error: () => {
                    this.showError(`Background mode request failed for job #${jobId}.`);
                    this.updateJobRow(jobId); // Re-render
                }
            });
        }

        deleteJob(jobId) {
             $.ajax({
                url: pip_ajax_vars.ajax_url, type: 'POST',
                data: { action: 'pip_job_action', job_action: 'delete', job_id: jobId, nonce: pip_ajax_vars.nonce }, // Using generic job_action
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        $(`#job-${jobId}`).fadeOut(() => {
                            this.jobs.delete(jobId);
                            $(`#job-${jobId}`).remove();
                            this.updateBulkActionsVisibility();
                            this.updateStatistics();
                            if (this.jobs.size === 0) $('#pip-jobs-empty-state').show();
                        });
                        this.showSuccess(`Job #${jobId} deleted.`);
                    } else {
                        this.showError(response.data.message || `Failed to delete job #${jobId}.`);
                    }
                },
                error: () => this.showError(`Delete request failed for job #${jobId}.`)
            });
        }

        resetStuckJob(jobId) {
            $.ajax({
                url: pip_ajax_vars.ajax_url, type: 'POST',
                data: { action: 'pip_reset_job', job_id: jobId, nonce: pip_ajax_vars.nonce }, // This is a specific action
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        this.showSuccess(response.data.message || `Job #${jobId} has been reset.`);
                        // Server should have reset status to pending, loadExistingJobs will refresh all.
                        this.loadExistingJobs(); 
                    } else {
                        this.showError(response.data.message || `Failed to reset job #${jobId}.`);
                    }
                },
                error: () => this.showError(`Reset request failed for job #${jobId}.`)
            });
        }

        updateJobStates(jobsData) { // jobsData is the array from server
            this.jobs.clear();
            jobsData.forEach(job => {
                this.jobs.set(job.id, job); // Store full job object from server
            });
            this.updateStatistics();
            this.checkStuckJobs(); // Check after jobs are updated
        }
        
        updateJobRow(jobId) {
            const job = this.jobs.get(jobId);
            if (!job) return;

            const $row = $(`#job-${jobId}`);
            if (!$row.length) return; // Row not in DOM, perhaps after a delete

            // Update status display
            $row.find('.job-status').text(this.getJobStatusDisplay(job.status)).attr('class', `pip-status-badge pip-status-${job.status} job-status`);
            $row.attr('data-status', job.status);

            // Update progress
            let progressHtml = 'N/A';
            if (job.total_rows > 0) {
                const percentage = Math.round((job.processed_rows / job.total_rows) * 100);
                progressHtml = `
                    <div class="pip-job-progress-text job-progress">${job.processed_rows} / ${job.total_rows} (${percentage}%)</div>
                    <div class="pip-job-progress-bar"><div class="pip-job-progress-fill progress-bar" style="width: ${percentage}%;"></div></div>
                `;
            } else if (job.status === 'validated' || job.status === 'pending' || job.status === 'uploaded') {
                 progressHtml = 'Awaiting processing...';
            }
            $row.find('.pip-job-progress').html(progressHtml);

            // Update started_at and duration (assuming these fields are on the job object from server)
            $row.find('td:nth-child(6)').text(job.started_at_formatted || '—');
            $row.find('td:nth-child(7)').text(job.duration_formatted || '—');

            // Update actions
            $row.find('.pip-job-actions-cell').html(this.getActionsHtml(job));
        }

        updateJobStatusDisplay(jobId, statusKeyOrText) {
            const $statusCell = $(`#job-${jobId} .job-status`);
            if ($statusCell.length) {
                const statusText = pip_ajax_vars.strings[`status_${statusKeyOrText}`] || statusKeyOrText;
                $statusCell.text(statusText).attr('class', `pip-status-badge pip-status-${statusKeyOrText} job-status`);
            }
        }

        updateStatistics() {
            const stats = { pending: 0, running: 0, completed: 0, failed: 0, other: 0 };
            this.jobs.forEach(job => {
                switch (job.status) {
                    case 'pending': case 'validated': case 'paused': case 'uploaded': stats.pending++; break;
                    case 'running_ajax': case 'queued_async': case 'running_async': stats.running++; break;
                    case 'completed': stats.completed++; break;
                    case 'failed': stats.failed++; break;
                    default: stats.other++; break;
                }
            });
            $('#pip-stat-pending').text(stats.pending);
            $('#pip-stat-running').text(stats.running);
            $('#pip-stat-completed').text(stats.completed);
            $('#pip-stat-failed').text(stats.failed);
        }

        startAutoRefresh() {
            if (this.autoRefreshInterval) clearInterval(this.autoRefreshInterval);
            this.autoRefreshInterval = setInterval(() => {
                // Only refresh if page is visible and no modal/confirm is active (simplistic check)
                if (!document.hidden && !$('body').hasClass('modal-open') && $('.ui-dialog:visible').length === 0) {
                    const hasActiveJobs = Array.from(this.jobs.values())
                        .some(job => ['running_ajax', 'queued_async', 'running_async'].includes(job.status));
                    if (hasActiveJobs) {
                        this.loadExistingJobs();
                    } else {
                        // If no jobs are active, maybe refresh less frequently or only if there are pending jobs
                        const hasPendingJobs = Array.from(this.jobs.values())
                            .some(job => ['pending', 'validated', 'uploaded', 'paused'].includes(job.status));
                        if (hasPendingJobs) this.loadExistingJobs();
                    }
                }
            }, 7000); // Refresh interval (e.g., 7 seconds)
        }

        scanUploadDirectory() {
            this.showProgress('Scanning upload directory...');
            $.ajax({
                url: pip_ajax_vars.ajax_url, type: 'POST',
                data: { action: 'pip_scan_files', nonce: pip_ajax_vars.nonce },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        this.showSuccess(response.data.message || 'Scan complete.');
                        if (response.data.errors && response.data.errors.length > 0) {
                            this.showError(`Scan found issues: ${response.data.errors.join('; ')}`, 'warning');
                        }
                        this.loadExistingJobs(); 
                    } else {
                        this.showError(response.data.message || 'Failed to scan directory.');
                    }
                },
                error: () => this.showError('Scan directory request failed.')
            });
        }

        updateBulkActionsVisibility() {
            const checkedCount = $('.pip-job-checkbox:checked').length;
            if (checkedCount > 0) {
                $('.pip-bulk-actions').show();
                $('.pip-selected-count').text(`${checkedCount} selected`);
            } else {
                $('.pip-bulk-actions').hide();
            }
            $('#pip-select-all-jobs').prop('checked', checkedCount > 0 && checkedCount === $('.pip-job-checkbox').length);
        }

        handleBulkAction(action) {
            const $checkedBoxes = $('.pip-job-checkbox:checked');
            if ($checkedBoxes.length === 0) {
                this.showError('No jobs selected for bulk action.');
                return;
            }
            const jobIds = $checkedBoxes.map((i, el) => $(el).val()).get();
            
            let confirmMessage = `Are you sure you want to ${action} ${jobIds.length} job(s)?`;
            if (action === 'delete') confirmMessage = pip_ajax_vars.strings.confirm_delete || confirmMessage;

            if (['delete', 'cancel'].includes(action)) {
                if (!confirm(confirmMessage)) return;
            }

            this.showProgress(`${action.charAt(0).toUpperCase() + action.slice(1)}ing ${jobIds.length} selected job(s)...`);
            jobIds.forEach((jobId, index) => {
                setTimeout(() => { // Stagger bulk actions slightly
                    this.handleJobAction(jobId, action, $(`#job-${jobId} [data-action='${action}']`));
                }, index * 200); 
            });
            // Uncheck boxes after a delay
            setTimeout(() => {
                 $checkedBoxes.prop('checked', false);
                 this.updateBulkActionsVisibility();
            }, (jobIds.length * 200) + 1000);
        }
        
        startAllJobs() {
            const startableJobIds = [];
            this.jobs.forEach(job => {
                if (['pending', 'validated', 'paused', 'uploaded'].includes(job.status)) {
                    startableJobIds.push(job.id);
                }
            });
            if (startableJobIds.length === 0) {
                this.showError('No jobs available to start.');
                return;
            }
            this.showProgress(`Starting ${startableJobIds.length} job(s)...`);
            startableJobIds.forEach((jobId, index) => {
                setTimeout(() => this.startImport(jobId), index * 500);
            });
        }

        pauseAllJobs() {
            const runningAjaxJobIds = [];
            this.jobs.forEach(job => {
                if (job.status === 'running_ajax') {
                    runningAjaxJobIds.push(job.id);
                }
            });
            if (runningAjaxJobIds.length === 0) {
                this.showError('No AJAX jobs currently running to pause.');
                return;
            }
            this.showProgress(`Pausing ${runningAjaxJobIds.length} AJAX job(s)...`);
            runningAjaxJobIds.forEach((jobId, index) => {
                setTimeout(() => this.pauseImport(jobId), index * 200);
            });
        }

        checkStuckJobs() {
            pipLog('Checking for stuck jobs...');
            const now = new Date().getTime();
            const stuckTimeout = 10 * 60 * 1000; // 10 minutes

            this.jobs.forEach(job => {
                const isPotentiallyRunning = ['running_ajax', 'running_async', 'queued_async'].includes(job.status);
                if (isPotentiallyRunning && job.started_at_formatted) {
                    // Attempt to parse started_at_formatted, assuming it's like 'YYYY-MM-DD HH:MM:SS'
                    // This parsing is simplistic and might need adjustment based on actual date format from server.
                    const parts = job.started_at_formatted.split(/[- :]/);
                    let startTime = 0;
                    if (parts.length === 5) { // YYYY-MM-DD HH:MM
                         startTime = new Date(parts[0], parts[1] - 1, parts[2], parts[3], parts[4]).getTime();
                    } else if (parts.length >= 6) { // YYYY-MM-DD HH:MM:SS
                         startTime = new Date(parts[0], parts[1] - 1, parts[2], parts[3], parts[4], parts[5]).getTime();
                    }
                    
                    if (startTime && (now - startTime > stuckTimeout)) {
                        pipLog(`Job #${job.id} may be stuck (status: ${job.status}, started: ${job.started_at_formatted}). Adding reset button.`);
                        const $actionsCell = $(`#job-${job.id} .pip-job-actions-cell`);
                        if ($actionsCell.find('.pip-job-action[data-action="reset"]').length === 0) {
                            const resetButtonHtml = `<button class="pip-action-button pip-job-action" data-job-id="${job.id}" data-action="reset" title="${pip_ajax_vars.strings.reset_label || 'Reset Stuck Job'}" style="background: #ff9800; color: white;"><span class="dashicons dashicons-update"></span></button>`;
                            $actionsCell.append(resetButtonHtml);
                        }
                    }
                }
            });
        }

        delay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

        showNotice(message, type = 'info', duration = 5000) {
            const $notice = $(`<div class="notice notice-${type} is-dismissible pip-dynamic-notice"><p></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>`);
            $notice.find('p').text(message);
            $('.wrap h1').first().after($notice);
            $notice.fadeIn();
            const timer = setTimeout(() => {
                $notice.fadeOut(() => $notice.remove());
            }, duration);
            $notice.find('.notice-dismiss').on('click', () => {
                clearTimeout(timer);
                $notice.fadeOut(() => $notice.remove());
            });
        }
        showProgress(message, duration = 3000) { this.showNotice(message, 'info', duration); }
        showSuccess(message, duration = 3000) { this.showNotice(message, 'success', duration); }
        showError(message, type = 'error', duration = 5000) { 
             if (type === 'warning') this.showNotice(message, 'warning', duration);
             else this.showNotice(message, 'error', duration); 
        }
    }

    $(document).ready(function() {
        if (typeof pip_ajax_vars !== 'undefined' && pip_ajax_vars.is_pip_page) {
            window.pipBatchController = new PipBatchProcessor();
        } else {
            console.log('Power Importer Pro: Not on the importer page or pip_ajax_vars not defined. Controller not initialized.');
        }
    });

})(jQuery);
