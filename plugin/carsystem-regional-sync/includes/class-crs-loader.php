<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Loader
{
    public static function init(): void
    {
        self::require_files();

        register_activation_hook(CRS_SYNC_PLUGIN_FILE, [Activator::class, 'activate']);
        register_deactivation_hook(CRS_SYNC_PLUGIN_FILE, [Activator::class, 'deactivate']);

        Plugin::instance()->boot();
    }

    private static function require_files(): void
    {
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-activator.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-plugin.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-settings.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-dictionary.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-regionalizer.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-primary-regionalization-runner.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-sync-map-repository.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-sync-log-repository.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-logger.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-api-client.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-lock.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-sync-runner.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-security.php';
        require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-admin-page.php';
    }
}
