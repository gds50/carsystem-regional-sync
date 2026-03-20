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
    }
}
