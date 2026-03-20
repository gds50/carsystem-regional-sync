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
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_admin']);
        add_action('admin_post_crs_test_connection', [$this, 'handle_test_connection']);
        add_action('admin_post_crs_run_primary_regionalization', [$this, 'handle_primary_regionalization']);
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

        try {
            $result = $this->perform_connection_test($settings);
            $identity = (string) ($result['slug'] ?? $result['name'] ?? $result['id'] ?? 'unknown');

            update_option(self::CONNECTION_TEST_OPTION_KEY, [
                'status'    => 'success',
                'message'   => sprintf('Connection successful. Remote user: %s', sanitize_text_field($identity)),
                'tested_at' => current_time('mysql', true),
            ], false);
        } catch (\Throwable $e) {
            update_option(self::CONNECTION_TEST_OPTION_KEY, [
                'status'    => 'error',
                'message'   => sanitize_text_field($e->getMessage()),
                'tested_at' => current_time('mysql', true),
            ], false);
        }

        wp_safe_redirect(Admin_Page::page_url(['tab' => 'connection']));
        exit;
    }

    public function handle_primary_regionalization(): void
    {
        Security::assert_admin_action('crs_run_primary_regionalization');

        $runner = new Primary_Regionalization_Runner(new Regionalizer());
        $runner->run(Settings::get());

        wp_safe_redirect(Admin_Page::page_url(['tab' => 'sync']));
        exit;
    }

    private function perform_connection_test(array $settings): array
    {
        $base = untrailingslashit((string) ($settings['source_url'] ?? ''));
        $url = $base . '/wp-json/wp/v2/users/me';
        $login = (string) ($settings['api_username'] ?? '');
        $password = (string) ($settings['api_application_password'] ?? '');

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
                'Accept'        => 'application/json',
            ],
            'user-agent' => 'CarsystemRegionalSync/' . CRS_SYNC_VERSION . '; ' . home_url('/'),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Remote API error: HTTP ' . $code);
        }

        if (! is_array($data)) {
            throw new \RuntimeException('Remote API returned invalid JSON payload.');
        }

        return $data;
    }
}
