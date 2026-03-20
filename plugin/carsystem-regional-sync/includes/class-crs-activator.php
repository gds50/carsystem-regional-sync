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

        if (false === get_option(CRS_SYNC_OPTION_KEY)) {
            add_option(CRS_SYNC_OPTION_KEY, Settings::defaults(), '', false);
        }

        update_option('crs_sync_plugin_version', CRS_SYNC_VERSION, false);
    }

    public static function deactivate(): void
    {
        // Keep deactivation side-effect free in Milestone 1.
    }

    private static function assert_environment(): void
    {
        global $wp_version;

        $minPhp = '8.1';
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
