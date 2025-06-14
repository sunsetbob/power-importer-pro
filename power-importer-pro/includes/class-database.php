<?php
/**
 * 数据库交互类
 *
 * 负责处理所有与插件自定义数据库表相关的操作。
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PIP_Database {

    private $wpdb;
    private $jobs_table;
    private $logs_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->jobs_table = $wpdb->prefix . 'pip_import_jobs';
        $this->logs_table = $wpdb->prefix . 'pip_import_logs';
    }

    /**
     * 创建一个新任务
     * @param string $filename
     * @param string $filepath
     * @return int|false 新任务的ID或false
     */
    public function create_job( $filename, $filepath ) {
        $inserted = $this->wpdb->insert(
            $this->jobs_table,
            [ 
                'file_name'  => $filename,
                'file_path'  => $filepath,
                'status'     => 'pending',
                'created_at' => current_time('mysql', 1), // 使用UTC时间
            ]
        );
        return $inserted ? $this->wpdb->insert_id : false;
    }

    /**
     * 更新任务状态和数据
     * @param int $job_id
     * @param array $data 要更新的数据数组
     */
    public function update_job( $job_id, $data ) {
        if ( empty($job_id) ) return;
        $this->wpdb->update($this->jobs_table, $data, ['id' => $job_id]);
    }
    
    /**
     * 获取一个任务的信息
     * @param int $job_id
     * @return object|null
     */
    public function get_job( $job_id ) {
        if ( empty($job_id) ) return null;
        return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->jobs_table} WHERE id = %d", $job_id ) );
    }

    /**
     * 获取最新的任务列表
     * @param int $limit
     * @return array
     */
    public function get_recent_jobs( $limit = 50 ) {
        return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->jobs_table} ORDER BY id DESC LIMIT %d", $limit ) );
    }

    /**
     * 写入一条日志
     * @param int $job_id
     * @param string $message
     * @param string $level
     */
    public function add_log( $job_id, $message, $level = 'INFO' ) {
        if ( $job_id > 0 ) {
            $this->wpdb->insert(
                $this->logs_table,
                [
                    'job_id'        => $job_id,
                    'log_level'     => $level,
                    'message'       => $message,
                    'log_timestamp' => current_time('mysql', 1),
                ]
            );
        }
        // 我们依然保留服务器级的日志，方便深层调试
        error_log("Power Importer Pro [{$level}] (Job #{$job_id}): " . $message);
    }
    
    /**
     * 获取一个任务的所有日志
     * @param int $job_id
     * @return array
     */
    public function get_logs_for_job( $job_id ) {
        if ( empty($job_id) ) return [];
        return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->logs_table} WHERE job_id = %d ORDER BY log_id ASC", $job_id ) );
    }

    /**
     * 删除一个任务及其所有日志
     * @param int $job_id
     */
    public function delete_job_and_logs( $job_id ) {
        if ( empty($job_id) ) return;
        $this->wpdb->delete( $this->jobs_table, [ 'id' => $job_id ], [ '%d' ] );
        $this->wpdb->delete( $this->logs_table, [ 'job_id' => $job_id ], [ '%d' ] );
    }

    /**
     * 清理所有已结束的任务及其日志
     */
    public function clear_old_jobs() {
        $job_ids_to_delete = $this->wpdb->get_col(
            "SELECT id FROM {$this->jobs_table} WHERE status IN ('completed', 'failed', 'cancelled')"
        );

        if ( ! empty( $job_ids_to_delete ) ) {
            $ids_placeholder = implode( ',', array_fill( 0, count($job_ids_to_delete), '%d' ) );
            
            // 一次性删除所有相关的日志
            $this->wpdb->query(
                $this->wpdb->prepare( "DELETE FROM {$this->logs_table} WHERE job_id IN ($ids_placeholder)", $job_ids_to_delete )
            );

            // 一次性删除所有相关的任务
            $this->wpdb->query(
                $this->wpdb->prepare( "DELETE FROM {$this->jobs_table} WHERE id IN ($ids_placeholder)", $job_ids_to_delete )
            );
        }
    }
}