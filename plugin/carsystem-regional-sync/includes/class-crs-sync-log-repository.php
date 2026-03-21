<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Log_Repository
{
    private const DEFAULT_LOG_RETENTION_DAYS = 60;

    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crs_sync_logs';
    }

    public function insert(array $data): int
    {
        global $wpdb;

        if (! $this->table_exists()) {
            return 0;
        }

        $wpdb->insert($this->table(), $data);
        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): void
    {
        global $wpdb;

        if ($id <= 0 || ! $this->table_exists()) {
            return;
        }

        $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    public function latest(int $limit = 20): array
    {
        global $wpdb;

        if (! $this->table_exists()) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d",
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function latest_by_run_type(string $runType, int $limit = 1, string $status = ''): array
    {
        global $wpdb;

        if (! $this->table_exists()) {
            return [];
        }

        $limit = max(1, $limit);
        $runType = sanitize_key($runType);
        $status = sanitize_key($status);

        if ($runType === '') {
            return [];
        }

        if ($status !== '') {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE run_type = %s AND status = %s ORDER BY id DESC LIMIT %d",
                $runType,
                $status,
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE run_type = %s ORDER BY id DESC LIMIT %d",
                $runType,
                $limit
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function maybe_cleanup_old_logs(): void
    {
        $lastCleanup = (int) get_option('crs_sync_logs_last_cleanup_at', 0);
        $now = time();

        if ($lastCleanup > 0 && ($now - $lastCleanup) < DAY_IN_SECONDS) {
            return;
        }

        $this->cleanup_old_logs(self::DEFAULT_LOG_RETENTION_DAYS);
        update_option('crs_sync_logs_last_cleanup_at', $now, false);
    }

    public function cleanup_old_logs(int $retentionDays): int
    {
        global $wpdb;

        if (! $this->table_exists()) {
            return 0;
        }

        $retentionDays = max(1, $retentionDays);
        $cutoffUtc = gmdate('Y-m-d H:i:s', time() - ($retentionDays * DAY_IN_SECONDS));

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE started_at < %s",
                $cutoffUtc
            )
        );

        return is_int($deleted) ? $deleted : 0;
    }

    private function table_exists(): bool
    {
        global $wpdb;

        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $this->table());
        $exists = $wpdb->get_var($sql);

        return is_string($exists) && $exists !== '';
    }
}
