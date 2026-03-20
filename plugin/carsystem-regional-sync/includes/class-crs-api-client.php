<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Api_Client
{
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
            'per_page' => $perPage,
        ]);
    }

    public function fetch_categories(int $page = 1, int $perPage = 100): array
    {
        return $this->get('/wp-json/wc/v3/products/categories', [
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    public function fetch_pages(int $page = 1, int $perPage = 100): array
    {
        return $this->get('/wp-json/wp/v2/pages', [
            'page'     => $page,
            'per_page' => $perPage,
            'status'   => 'publish,draft,private,pending',
        ]);
    }

    private function get(string $path, array $query = []): array
    {
        $base = untrailingslashit((string) ($this->settings['source_url'] ?? ''));
        $url = add_query_arg($query, $base . $path);
        $login = (string) ($this->settings['api_username'] ?? '');
        $password = (string) ($this->settings['api_application_password'] ?? '');

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
