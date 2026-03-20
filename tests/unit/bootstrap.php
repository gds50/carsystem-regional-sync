<?php

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../../');
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string
    {
        $value = (string) $value;
        $value = strip_tags($value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim((string) $value);
    }
}

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value): string
    {
        $value = (string) $value;
        $value = str_replace("\r", '', $value);
        $lines = array_map('sanitize_text_field', explode("\n", $value));
        return trim(implode("\n", $lines));
    }
}

if (! function_exists('sanitize_user')) {
    function sanitize_user($username): string
    {
        $username = (string) $username;
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email($email): string
    {
        $email = (string) $email;
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw($url): string
    {
        return (string) filter_var((string) $url, FILTER_SANITIZE_URL);
    }
}

if (! function_exists('sanitize_title')) {
    function sanitize_title($title): string
    {
        $title = (string) $title;
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9_\-\s]/', '', $title);
        $title = preg_replace('/[\s_]+/', '-', (string) $title);
        $title = trim((string) $title, '-');

        return $title;
    }
}

if (! function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []): array
    {
        $args = is_array($args) ? $args : [];
        $defaults = is_array($defaults) ? $defaults : [];

        return array_merge($defaults, $args);
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, $default = false)
    {
        return $default;
    }
}

if (! function_exists('wp_timezone')) {
    function wp_timezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}

require_once __DIR__ . '/../../plugin/carsystem-regional-sync/includes/class-crs-settings.php';
require_once __DIR__ . '/../../plugin/carsystem-regional-sync/includes/class-crs-dictionary.php';
