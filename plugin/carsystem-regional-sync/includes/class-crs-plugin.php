<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;
    private const CONNECTION_TEST_OPTION_KEY = 'crs_sync_last_connection_test';

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('init', [$this, 'maybe_ensure_cron_schedule']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_admin']);
        add_action(CRS_SYNC_CRON_HOOK, [$this, 'handle_cron_sync']);
        add_action('update_option_' . CRS_SYNC_OPTION_KEY, [$this, 'handle_settings_updated'], 10, 2);
        add_action('admin_post_crs_test_connection', [$this, 'handle_test_connection']);
        add_action('admin_post_crs_run_primary_regionalization', [$this, 'handle_primary_regionalization']);
        add_action('admin_post_crs_run_sync_now', [$this, 'handle_run_sync_now']);
    }

    public function register_settings(): void
    {
        Settings::register();
    }

    public function register_admin(): void
    {
        (new Admin_Page())->register();
    }

    public function handle_test_connection(): void
    {
        Security::assert_admin_action('crs_test_connection');

        $settings = Settings::get();
        $logger = new Logger();
        $logId = $logger->start('connection_test');

        try {
            $result = (new Api_Client($settings))->test_connection();
            $identity = (string) ($result['slug'] ?? $result['name'] ?? $result['id'] ?? 'unknown');
            $message = sprintf('Connection successful. Remote user: %s', sanitize_text_field($identity));

            update_option(self::CONNECTION_TEST_OPTION_KEY, [
                'status'    => 'success',
                'message'   => $message,
                'tested_at' => current_time('mysql', true),
            ], false);

            $logger->finish($logId, [
                'status'        => 'success',
                'checked_count' => 1,
                'message'       => $message,
            ]);
        } catch (\Throwable $e) {
            $errorMessage = $this->sanitize_error_message($e->getMessage());

            update_option(self::CONNECTION_TEST_OPTION_KEY, [
                'status'    => 'error',
                'message'   => $errorMessage,
                'tested_at' => current_time('mysql', true),
            ], false);

            $logger->finish($logId, [
                'status'      => 'error',
                'error_count' => 1,
                'message'     => $errorMessage,
            ]);
        }

        wp_safe_redirect(Admin_Page::page_url(['tab' => 'connection']));
        exit;
    }

    public function handle_primary_regionalization(): void
    {
        Security::assert_admin_action('crs_run_primary_regionalization');

        $logger = new Logger();
        $logId = $logger->start('primary_regionalization');
        $runner = new Primary_Regionalization_Runner(new Regionalizer());
        $summary = $runner->run(Settings::get());

        $logger->finish($logId, [
            'status'        => (string) ($summary['status'] ?? 'success'),
            'checked_count' => (int) ($summary['checked_count'] ?? 0),
            'updated_count' => (int) ($summary['updated_count'] ?? 0),
            'skipped_count' => (int) ($summary['skipped_count'] ?? 0),
            'error_count'   => (int) ($summary['error_count'] ?? 0),
            'message'       => (string) ($summary['message'] ?? ''),
        ]);

        wp_safe_redirect(Admin_Page::page_url(['tab' => 'sync']));
        exit;
    }

    public function handle_run_sync_now(): void
    {
        Security::assert_admin_action('crs_run_sync_now');
        Sync_Runner::make()->run_sync('manual');

        wp_safe_redirect(Admin_Page::page_url(['tab' => 'sync']));
        exit;
    }

    public function handle_cron_sync(): void
    {
        Sync_Runner::make()->run_sync('cron');
    }

    /**
     * @param array|string $oldValue
     * @param array|string $newValue
     */
    public function handle_settings_updated($oldValue, $newValue): void
    {
        $scheduler = new Cron_Scheduler();
        $settings = Settings::get();
        $scheduler->ensure_scheduled($settings);
    }

    public function maybe_ensure_cron_schedule(): void
    {
        $scheduler = new Cron_Scheduler();
        $settings = Settings::get();
        $scheduler->ensure_scheduled($settings);
    }

    private function sanitize_error_message(string $message): string
    {
        $sanitized = preg_replace('/Authorization:\s*Basic\s+[A-Za-z0-9+\/=]+/i', 'Authorization: [REDACTED]', $message);
        $sanitized = preg_replace('/(api_application_password=)[^&\s]+/i', '$1[REDACTED]', (string) $sanitized);
        $sanitized = preg_replace('/(password=)[^&\s]+/i', '$1[REDACTED]', (string) $sanitized);
        $sanitized = sanitize_text_field((string) $sanitized);

        if ($sanitized === '') {
            return 'Connection test failed.';
        }

        return substr($sanitized, 0, 500);
    }
}
