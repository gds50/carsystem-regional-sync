<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Api_Client
{
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_BACKOFF_SECONDS = [1, 2, 4];

    /** @var array */
    private $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function test_connection(): array
    {
        return $this->get('/wp-json/wp/v2/users/me');
    }

    public function fetch_products(int $page = 1, int $perPage = 100): array
    {
        return $this->get('/wp-json/wc/v3/products', [
            'page'     => $page,
            'per_page' => $this->sanitize_per_page($perPage),
        ]);
    }

    public function fetch_categories(int $page = 1, int $perPage = 100): array
    {
        return $this->get('/wp-json/wc/v3/products/categories', [
            'page'     => $page,
            'per_page' => $this->sanitize_per_page($perPage),
        ]);
    }

    public function fetch_pages(int $page = 1, int $perPage = 100): array
    {
        $query = [
            'page'     => $page,
            'per_page' => $this->sanitize_per_page($perPage),
            'status'   => 'publish,draft,private,pending',
            'context'  => 'edit',
        ];

        try {
            return $this->get('/wp-json/wp/v2/pages', $query);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $isAuthError = strpos($message, 'HTTP 401') !== false || strpos($message, 'HTTP 403') !== false;

            if (! $isAuthError) {
                throw $e;
            }
        }

        unset($query['context']);

        return $this->get('/wp-json/wp/v2/pages', $query);
    }

    private function get(string $path, array $query = []): array
    {
        $this->assert_connection_settings();

        $base = untrailingslashit((string) ($this->settings['source_url'] ?? ''));
        $url = add_query_arg($query, $base . $path);
        $login = (string) ($this->settings['api_username'] ?? '');
        $password = (string) ($this->settings['api_application_password'] ?? '');

        $lastErrorMessage = 'Remote API request failed.';

        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            $response = wp_remote_get($url, [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
                    'Accept'        => 'application/json',
                ],
                'user-agent' => 'CarsystemRegionalSync/' . CRS_SYNC_VERSION . '; ' . home_url('/'),
            ]);

            if (is_wp_error($response)) {
                $lastErrorMessage = $this->sanitize_error_message((string) $response->get_error_message());

                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    sleep((int) self::RETRY_BACKOFF_SECONDS[$attempt - 1]);
                    continue;
                }

                throw new \RuntimeException($lastErrorMessage);
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($code >= 200 && $code < 300) {
                if (! is_array($data)) {
                    throw new \RuntimeException('Remote API returned invalid JSON payload.');
                }

                return $data;
            }

            $lastErrorMessage = 'Remote API error: HTTP ' . $code;

            if (($code === 429 || $code >= 500) && $attempt < self::MAX_RETRY_ATTEMPTS) {
                sleep((int) self::RETRY_BACKOFF_SECONDS[$attempt - 1]);
                continue;
            }

            throw new \RuntimeException($lastErrorMessage);
        }

        throw new \RuntimeException($lastErrorMessage);
    }

    private function assert_connection_settings(): void
    {
        $sourceUrl = esc_url_raw((string) ($this->settings['source_url'] ?? ''));
        $login = sanitize_user((string) ($this->settings['api_username'] ?? ''), true);
        $password = trim((string) ($this->settings['api_application_password'] ?? ''));

        if ($sourceUrl === '' || ! wp_http_validate_url($sourceUrl)) {
            throw new \RuntimeException('Invalid source URL. Check Connection settings.');
        }

        if ($login === '') {
            throw new \RuntimeException('API username is required. Check Connection settings.');
        }

        if ($password === '') {
            throw new \RuntimeException('API application password is required. Check Connection settings.');
        }
    }

    private function sanitize_error_message(string $message): string
    {
        $sanitized = preg_replace('/Authorization:\s*Basic\s+[A-Za-z0-9+\/=]+/i', 'Authorization: [REDACTED]', $message);
        $sanitized = preg_replace('/(api_application_password=)[^&\s]+/i', '$1[REDACTED]', (string) $sanitized);
        $sanitized = preg_replace('/(password=)[^&\s]+/i', '$1[REDACTED]', (string) $sanitized);
        $sanitized = sanitize_text_field((string) $sanitized);

        if ($sanitized === '') {
            return 'Remote API request failed.';
        }

        return substr($sanitized, 0, 500);
    }

    private function sanitize_per_page(int $perPage): int
    {
        if ($perPage < 1) {
            return 1;
        }

        if ($perPage > 100) {
            return 100;
        }

        return $perPage;
    }
}
