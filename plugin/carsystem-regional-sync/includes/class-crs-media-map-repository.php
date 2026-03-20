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
        $payload['created_at'] = current_time('mysql', true);

        $sql = $wpdb->prepare(
            "INSERT INTO {$this->table()}
            (object_type, object_remote_id, remote_media_url, local_attachment_id, remote_media_hash, last_synced_at, last_operation_status, last_error_message, created_at, updated_at)
            VALUES (%s, %d, %s, %d, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                remote_media_url = VALUES(remote_media_url),
                local_attachment_id = VALUES(local_attachment_id),
                last_synced_at = VALUES(last_synced_at),
                last_operation_status = VALUES(last_operation_status),
                last_error_message = VALUES(last_error_message),
                updated_at = VALUES(updated_at)",
            $payload['object_type'],
            $payload['object_remote_id'],
            $payload['remote_media_url'],
            $payload['local_attachment_id'],
            $payload['remote_media_hash'],
            $payload['last_synced_at'],
            $payload['last_operation_status'],
            $payload['last_error_message'],
            $payload['created_at'],
            $payload['updated_at']
        );

        $wpdb->query($sql);
    }

    private function table_exists(): bool
    {
        global $wpdb;

        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $this->table());
        $exists = $wpdb->get_var($sql);

        return is_string($exists) && $exists !== '';
    }
}
