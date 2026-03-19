<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Map_Repository
{
    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crs_sync_map';
    }

    public function find_by_remote(string $objectType, int $remoteId): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE object_type = %s AND remote_id = %d LIMIT 1",
            $objectType,
            $remoteId
        );

        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function upsert(array $data): void
    {
        global $wpdb;

        $existing = $this->find_by_remote((string) $data['object_type'], (int) $data['remote_id']);

        $payload = [
            'object_type'           => (string) $data['object_type'],
            'remote_id'             => (int) $data['remote_id'],
            'local_id'              => (int) $data['local_id'],
            'remote_slug'           => (string) ($data['remote_slug'] ?? ''),
            'remote_modified_gmt'   => $data['remote_modified_gmt'] ?? null,
            'payload_hash'          => (string) ($data['payload_hash'] ?? ''),
            'last_synced_at'        => current_time('mysql', true),
            'last_operation_status' => (string) ($data['last_operation_status'] ?? 'success'),
            'last_error_message'    => $data['last_error_message'] ?? null,
            'updated_at'            => current_time('mysql', true),
        ];

        if ($existing) {
            $wpdb->update($this->table(), $payload, ['id' => (int) $existing['id']]);
            return;
        }

        $payload['created_at'] = current_time('mysql', true);
        $wpdb->insert($this->table(), $payload);
    }
}
