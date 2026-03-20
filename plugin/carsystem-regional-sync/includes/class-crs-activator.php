<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Activator
{
    public static function activate(): void
    {
        self::assert_environment();
        self::create_tables();

        if (false === get_option(CRS_SYNC_OPTION_KEY)) {
            add_option(CRS_SYNC_OPTION_KEY, Settings::defaults(), '', false);
        }

        (new Cron_Scheduler())->ensure_scheduled(Settings::get());
        update_option('crs_sync_plugin_version', CRS_SYNC_VERSION, false);
    }

    public static function deactivate(): void
    {
        (new Cron_Scheduler())->unschedule();
    }

    private static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $mapTable = $wpdb->prefix . 'crs_sync_map';
        $logTable = $wpdb->prefix . 'crs_sync_logs';

        $sqlMap = "CREATE TABLE {$mapTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(32) NOT NULL,
            remote_id BIGINT UNSIGNED NOT NULL,
            local_id BIGINT UNSIGNED NOT NULL,
            remote_slug VARCHAR(200) NOT NULL DEFAULT '',
            remote_modified_gmt DATETIME NULL,
            payload_hash CHAR(64) NOT NULL DEFAULT '',
            last_synced_at DATETIME NULL,
            last_operation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            last_error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY object_remote_unique (object_type, remote_id),
            KEY object_local_idx (object_type, local_id),
            KEY remote_slug_idx (remote_slug),
            KEY last_operation_status_idx (last_operation_status)
        ) {$charsetCollate};";

        $sqlLogs = "CREATE TABLE {$logTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_type VARCHAR(32) NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            checked_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_count INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0,
            message TEXT NULL,
            context_json LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY run_type_idx (run_type),
            KEY status_idx (status),
            KEY started_at_idx (started_at)
        ) {$charsetCollate};";

        dbDelta($sqlMap);
        dbDelta($sqlLogs);
    }

    private static function assert_environment(): void
    {
        global $wp_version;

        $minPhp = '7.4';
        $minWp = '6.9';
        $phpOk = version_compare(PHP_VERSION, $minPhp, '>=');
        $wpOk = is_string($wp_version) && version_compare($wp_version, $minWp, '>=');

        if ($phpOk && $wpOk) {
            return;
        }

        deactivate_plugins(plugin_basename(CRS_SYNC_PLUGIN_FILE));

        $message = sprintf(
            /* translators: 1: minimum PHP version, 2: minimum WP version. */
            esc_html__('Carsystem Regional Sync requires PHP %1$s+ and WordPress %2$s+.', 'carsystem-regional-sync'),
            $minPhp,
            $minWp
        );

        wp_die($message, esc_html__('Plugin Activation Error', 'carsystem-regional-sync'), ['response' => 400]);
    }
}
