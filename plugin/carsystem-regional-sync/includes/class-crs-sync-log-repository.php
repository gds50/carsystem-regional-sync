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
        $wpdb->insert($this->table(), $data);
        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): void
    {
        global $wpdb;
        $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    public function latest(int $limit = 20): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d",
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }
}
