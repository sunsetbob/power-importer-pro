=== Power Importer Pro ===
Contributors: [Your Name]
Tags: woocommerce, import, products, csv, bulk, background process, async, job queue
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 1.5.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A professional tool to import WooCommerce products from a CSV file, featuring a robust background job queue, detailed logging, and an intuitive user interface.

== Description ==

Power Importer Pro is the definitive solution for store owners and developers who need to import a large number of WooCommerce products reliably and efficiently. Forget browser timeouts and uncertainty. This plugin leverages WooCommerce's own Action Scheduler to process everything in the background, allowing you to upload your files, close your browser, and let the importer do the heavy lifting.

**Key Features:**

*   **True Background Processing**: Utilizes the robust Action Scheduler library to handle large files and extensive image downloads without any browser timeouts.
*   **Multi-File & Sequential Job Queue**: Upload multiple CSV/TXT files at once. Each file is intelligently queued as a separate job and processed sequentially, ensuring server stability.
*   **Intelligent SKU-based Deduplication**: Automatically skips products (both simple and variations) if their SKU already exists, making it safe to re-run imports or update data without creating duplicates.
*   **Advanced Image Handling**:
    *   Reliably downloads images from remote URLs.
    *   Optimizes images by resizing to a max-width/height of 1000px and converting to the high-performance WebP format.
    *   **Logical File Organization**: Organizes imported images into clean, category-based subdirectories under `/wp-content/uploads/images/` (e.g., `/images/coffee-makers/`), separating them from regular media uploads.
*   **Complete Job & Log Management UI**:
    *   A clean user interface under **Products > Power Importer** to monitor the status (`Pending`, `Running`, `Completed`, `Failed`) and progress of each import job.
    *   **Live Progress Bars**: The jobs table auto-refreshes, providing real-time feedback without needing to reload the page.
    *   **Full User Control**: Cancel pending/running jobs, retry failed jobs, or delete individual job records and their associated logs.
    *   **Bulk Cleanup**: A "Clear All Finished Jobs" button to easily maintain a clean job list.
    *   **Detailed Logging**: View detailed, color-coded logs for every job to easily diagnose any issues, such as skipped rows or failed image downloads.
*   **Professional & Maintainable Codebase**: Built with best practices, including a decoupled, object-oriented structure (UI, Database, Importer Logic) for long-term stability and easy extension.

This plugin is the ultimate tool for migrating products, performing bulk updates, or managing data feeds from suppliers.

== Installation ==

1.  Upload the `power-importer-pro` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Upon activation, the plugin will create two new database tables: `wp_pip_import_jobs` and `wp_pip_import_logs`.
4.  Navigate to **Products > Power Importer** in your WordPress admin dashboard to start using the tool.

== Frequently Asked Questions ==

= What format should my CSV/TXT file be in? =

The file must be a standard UTF-8 encoded CSV (Comma-Separated Values) or TXT file. The first row must be a header row. The plugin is specifically designed to work with the column structure provided in the original `testp.txt` file.

= Where are my uploaded CSV files stored? =

For security and processing, your uploaded files are stored in `/wp-content/uploads/power-importer-pro-file/`.

= Where are the imported product images stored? =

All imported images are stored in a structured format: `/wp-content/uploads/images/[category-slug]/`. This keeps them organized and separate from your main media library uploads.

= My import job is stuck in "Pending". What should I do? =

This indicates that your website's WP-Cron is not running. WP-Cron is essential for background tasks. The recommended solution is to disable the default WP-Cron and set up a real system cron job on your server. Please refer to the official WordPress documentation on [how to configure cron jobs](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/).

== Screenshots ==

1.  The main importer interface, showing the multi-file upload form and the jobs list.
2.  The jobs list displaying real-time progress with statuses like Completed, Running, and Pending.
3.  The detailed, color-coded log view for a single import job, accessible via the "View Log" link.
4.  Action buttons (Cancel, Retry, Delete) provide full control over each job.

== Changelog ==

= 1.5.0 (Current Version) =
*   FEAT: Added a "Clear All Finished Jobs" button for easy log management.
*   FEAT: Added "Cancel", "Retry", and "Delete" actions for individual jobs.
*   FEAT: Implemented a database-backed job and log management system.
*   FEAT: Added real-time progress bars with AJAX auto-refresh.
*   FEAT: Implemented multi-file uploads with a sequential job queue.
*   FEAT: All import tasks now run in the background via Action Scheduler.
*   ENHANCEMENT: Re-architected the plugin into a professional, object-oriented structure with a dedicated database class.
*   ENHANCEMENT: Image uploads are now organized by product category for better file management.
*   FIX: Resolved all known issues with environment-specific failures (file permissions, function availability, AJAX context, parameter passing).

= 1.0.0 =
*   Initial release based on a command-line script.

== Upgrade Notice ==

= 1.5.0 =
This version introduces new database tables (`wp_pip_import_jobs`, `wp_pip_import_logs`) and a more robust architecture. Please deactivate and reactivate the plugin after updating to ensure the database schema is correctly applied.