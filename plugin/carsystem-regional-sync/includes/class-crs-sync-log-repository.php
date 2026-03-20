<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Log_Repository
{
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

    private function table_exists(): bool
    {
        global $wpdb;

        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $this->table());
        $exists = $wpdb->get_var($sql);

        return is_string($exists) && $exists !== '';
    }
}
