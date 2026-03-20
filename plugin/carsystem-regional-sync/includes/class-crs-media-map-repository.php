<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Media_Map_Repository
{
    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crs_sync_media_map';
    }

    public function find_by_remote_url(string $objectType, int $objectRemoteId, string $remoteMediaUrl): ?array
    {
        global $wpdb;

        if (! $this->table_exists()) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE object_type = %s AND object_remote_id = %d AND remote_media_url = %s LIMIT 1",
            $objectType,
            $objectRemoteId,
            $remoteMediaUrl
        );

        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function upsert(array $data): void
    {
        global $wpdb;

        if (! $this->table_exists()) {
            return;
        }

        $objectType = (string) ($data['object_type'] ?? '');
        $objectRemoteId = (int) ($data['object_remote_id'] ?? 0);
        $remoteMediaUrl = (string) ($data['remote_media_url'] ?? '');

        if ($objectType === '' || $objectRemoteId <= 0 || $remoteMediaUrl === '') {
            return;
        }

        $existing = $this->find_by_remote_url($objectType, $objectRemoteId, $remoteMediaUrl);

        $payload = [
            'object_type'           => $objectType,
            'object_remote_id'      => $objectRemoteId,
            'remote_media_url'      => $remoteMediaUrl,
            'local_attachment_id'   => (int) ($data['local_attachment_id'] ?? 0),
            'remote_media_hash'     => (string) ($data['remote_media_hash'] ?? ''),
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

    private function table_exists(): bool
    {
        global $wpdb;

        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $this->table());
        $exists = $wpdb->get_var($sql);

        return is_string($exists) && $exists !== '';
    }
}
