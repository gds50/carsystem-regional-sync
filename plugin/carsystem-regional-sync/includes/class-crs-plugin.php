<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register_admin']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_crs_test_connection', [$this, 'handle_test_connection']);
        add_action('admin_post_crs_run_primary_regionalization', [$this, 'handle_primary_regionalization']);
        add_action('admin_post_crs_run_sync_now', [$this, 'handle_sync_now']);
        add_action(CRS_SYNC_CRON_HOOK, [$this, 'handle_cron']);
        add_action('update_option_' . CRS_SYNC_OPTION_KEY, [$this, 'handle_settings_updated'], 10, 2);
        add_filter('cron_schedules', [$this, 'register_custom_schedule']);
    }

    public function register_admin(): void
    {
        (new Admin_Page())->register();
    }

    public function register_settings(): void
    {
        Settings::register();
    }

    public function handle_settings_updated($oldValue, $newValue): void
    {
        $this->reschedule_sync();
    }

    public function register_custom_schedule(array $schedules): array
    {
        $schedules['crs_daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __('Once daily (Carsystem Regional Sync)', 'carsystem-regional-sync'),
        ];

        return $schedules;
    }

    public function reschedule_sync(): void
    {
        $settings = Settings::get();
        $enabled = ! empty($settings['auto_sync_enabled']);
        $existing = wp_next_scheduled(CRS_SYNC_CRON_HOOK);

        if ($existing) {
            wp_unschedule_event($existing, CRS_SYNC_CRON_HOOK);
        }

        if (! $enabled) {
            return;
        }

        $timestamp = Settings::next_run_timestamp($settings['sync_time'] ?? '02:30');
        wp_schedule_event($timestamp, 'crs_daily', CRS_SYNC_CRON_HOOK);
    }

    public function handle_test_connection(): void
    {
        Security::assert_admin_action('crs_test_connection');

        $client = new Api_Client(Settings::get());
        $runner = new Sync_Runner($client, new Logger());
        $runner->test_connection();

        wp_safe_redirect(Admin_Page::page_url(['message' => 'connection_tested']));
        exit;
    }

    public function handle_primary_regionalization(): void
    {
        Security::assert_admin_action('crs_run_primary_regionalization');

        $runner = Sync_Runner::make();
        $runner->run_primary_regionalization();

        wp_safe_redirect(Admin_Page::page_url(['message' => 'primary_regionalization_done']));
        exit;
    }

    public function handle_sync_now(): void
    {
        Security::assert_admin_action('crs_run_sync_now');

        $runner = Sync_Runner::make();
        $runner->run_sync('manual');

        wp_safe_redirect(Admin_Page::page_url(['message' => 'sync_finished']));
        exit;
    }

    public function handle_cron(): void
    {
        $runner = Sync_Runner::make();
        $runner->run_sync('cron');
    }
}
