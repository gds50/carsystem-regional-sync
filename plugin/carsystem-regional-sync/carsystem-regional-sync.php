<?php
/**
 * Plugin Name: Carsystem Regional Sync
 * Description: MVP plugin for syncing regional clone sites from carsystem.su via WordPress and WooCommerce REST API.
 * Version: 0.1.0
 * Author: OpenAI
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Text Domain: carsystem-regional-sync
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CRS_SYNC_VERSION', '0.1.0');
define('CRS_SYNC_PLUGIN_FILE', __FILE__);
define('CRS_SYNC_PLUGIN_DIR', __DIR__);
define('CRS_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CRS_SYNC_OPTION_KEY', 'crs_sync_settings');
define('CRS_SYNC_LOCK_KEY', 'crs_sync_lock');
define('CRS_SYNC_CRON_HOOK', 'crs_sync_daily_event');

require_once CRS_SYNC_PLUGIN_DIR . '/includes/class-crs-loader.php';

\CRS\Loader::init();
