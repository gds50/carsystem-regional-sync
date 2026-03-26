<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings
{
    private const PRODUCT_OBJECT_TYPE = 'product';

    public static function register(): void
    {
        register_setting(
            'crs_sync_settings_group',
            CRS_SYNC_OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [self::class, 'sanitize'],
                'default'           => self::defaults(),
            ]
        );
    }

    public static function defaults(): array
    {
        return [
            'source_url'                => 'https://carsystem.su',
            'api_username'              => '',
            'api_application_password'  => '',
            'region'                    => '',
            'city'                      => '',
            'area'                      => '',
            'replacement_dictionary'    => '',
            'partner_name'              => '',
            'partner_phone'             => '',
            'partner_email'             => '',
            'partner_address'           => '',
            'excluded_slugs'            => [
                'gde-kupit',
                'dostavka',
                'privacy-policy',
                'polzovatelskoe-soglashenie',
                'oplata',
            ],
            'excluded_product_remote_ids' => [],
            'excluded_product_local_ids' => [],
            'use_local_media_copy'      => 0,
            'source_local_base_path'    => '',
            'auto_sync_enabled'         => 1,
            'sync_time'                 => '02:30',
        ];
    }

    public static function get(): array
    {
        $value = get_option(CRS_SYNC_OPTION_KEY, []);

        return wp_parse_args(is_array($value) ? $value : [], self::defaults());
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $current = self::get();

        $settings = [
            'source_url'               => esc_url_raw(array_key_exists('source_url', $input) ? $input['source_url'] : ($current['source_url'] ?? $defaults['source_url'])),
            'api_username'             => sanitize_user(array_key_exists('api_username', $input) ? $input['api_username'] : ($current['api_username'] ?? '')),
            'api_application_password' => self::sanitize_password(array_key_exists('api_application_password', $input) ? (string) $input['api_application_password'] : '', (string) ($current['api_application_password'] ?? '')),
            'region'                   => sanitize_text_field(array_key_exists('region', $input) ? $input['region'] : ($current['region'] ?? '')),
            'city'                     => sanitize_text_field(array_key_exists('city', $input) ? $input['city'] : ($current['city'] ?? '')),
            'area'                     => sanitize_text_field(array_key_exists('area', $input) ? $input['area'] : ($current['area'] ?? '')),
            'replacement_dictionary'   => self::sanitize_dictionary((string) (array_key_exists('replacement_dictionary', $input) ? $input['replacement_dictionary'] : ($current['replacement_dictionary'] ?? ''))),
            'partner_name'             => sanitize_text_field(array_key_exists('partner_name', $input) ? $input['partner_name'] : ($current['partner_name'] ?? '')),
            'partner_phone'            => sanitize_text_field(array_key_exists('partner_phone', $input) ? $input['partner_phone'] : ($current['partner_phone'] ?? '')),
            'partner_email'            => sanitize_email(array_key_exists('partner_email', $input) ? $input['partner_email'] : ($current['partner_email'] ?? '')),
            'partner_address'          => sanitize_textarea_field((string) (array_key_exists('partner_address', $input) ? $input['partner_address'] : ($current['partner_address'] ?? ''))),
            'excluded_slugs'           => self::sanitize_excluded_slugs(array_key_exists('excluded_slugs', $input) ? $input['excluded_slugs'] : ($current['excluded_slugs'] ?? [])),
            'excluded_product_remote_ids' => self::sanitize_excluded_product_remote_ids(array_key_exists('excluded_product_remote_ids', $input) ? $input['excluded_product_remote_ids'] : ($current['excluded_product_remote_ids'] ?? [])),
            'excluded_product_local_ids' => self::sanitize_excluded_product_local_ids(array_key_exists('excluded_product_remote_ids', $input) ? $input['excluded_product_remote_ids'] : (array_key_exists('excluded_product_local_ids', $input) ? $input['excluded_product_local_ids'] : ($current['excluded_product_local_ids'] ?? []))),
            'use_local_media_copy'     => array_key_exists('use_local_media_copy', $input)
                ? (empty($input['use_local_media_copy']) ? 0 : 1)
                : (int) ($current['use_local_media_copy'] ?? 0),
            'source_local_base_path'   => self::sanitize_local_base_path((string) (array_key_exists('source_local_base_path', $input) ? $input['source_local_base_path'] : ($current['source_local_base_path'] ?? ''))),
            'auto_sync_enabled'        => array_key_exists('auto_sync_enabled', $input)
                ? (empty($input['auto_sync_enabled']) ? 0 : 1)
                : (int) ($current['auto_sync_enabled'] ?? $defaults['auto_sync_enabled']),
            'sync_time'                => self::sanitize_time((string) (array_key_exists('sync_time', $input) ? $input['sync_time'] : ($current['sync_time'] ?? $defaults['sync_time']))),
        ];

        return $settings;
    }

    public static function sanitize_dictionary(string $dictionary): string
    {
        $dictionary = str_replace(["\r\n", "\r"], "\n", $dictionary);
        $lines = array_filter(array_map('trim', explode("\n", $dictionary)));
        $validLines = [];

        foreach ($lines as $line) {
            if (strpos($line, '=>') === false) {
                continue;
            }

            [$from, $to] = array_map('trim', explode('=>', $line, 2));

            if ($from === '' || $to === '') {
                continue;
            }

            $validLines[] = $from . ' => ' . $to;
        }

        return implode("\n", $validLines);
    }

    public static function sanitize_excluded_slugs($raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/\r\n|\r|\n/', (string) $raw);
        $items = array_filter(array_map(static fn($item) => sanitize_title((string) $item), $items));
        $items = array_values(array_unique($items));

        return $items;
    }

    public static function sanitize_excluded_product_remote_ids($raw): array
    {
        $items = self::extract_product_ids_from_raw($raw);
        $result = [];
        $mapRepository = class_exists('\\CRS\\Sync_Map_Repository') ? new Sync_Map_Repository() : null;

        foreach ($items as $item) {
            $remoteId = self::resolve_remote_product_id((int) $item, $mapRepository);

            if ($remoteId > 0) {
                $result[$remoteId] = $remoteId;
            }
        }

        return array_values($result);
    }

    public static function sanitize_excluded_product_local_ids($raw): array
    {
        return self::extract_product_ids_from_raw($raw);
    }

    private static function resolve_remote_product_id(int $id, ?Sync_Map_Repository $mapRepository = null): int
    {
        if ($id <= 0) {
            return 0;
        }

        if ($mapRepository instanceof Sync_Map_Repository) {
            $mappingByLocal = $mapRepository->find_by_local(self::PRODUCT_OBJECT_TYPE, $id);
            if (is_array($mappingByLocal)) {
                $remoteId = (int) ($mappingByLocal['remote_id'] ?? 0);
                if ($remoteId > 0) {
                    return $remoteId;
                }
            }

            $mappingByRemote = $mapRepository->find_by_remote(self::PRODUCT_OBJECT_TYPE, $id);
            if (is_array($mappingByRemote)) {
                return $id;
            }
        }

        if (function_exists('get_post_type') && function_exists('get_post_meta') && get_post_type($id) === 'product') {
            $metaRemoteId = (int) get_post_meta($id, '_crs_remote_id', true);
            if ($metaRemoteId > 0) {
                return $metaRemoteId;
            }
        }

        return $id;
    }

    private static function extract_product_ids_from_raw($raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/\r\n|\r|\n/', (string) $raw);
        $result = [];

        foreach ($items as $item) {
            $line = trim((string) $item);

            if ($line === '') {
                continue;
            }

            $id = 0;

            if (preg_match('/(?:^|[?&])post=(\d+)/i', $line, $matches) === 1) {
                $id = (int) ($matches[1] ?? 0);
            } elseif (preg_match('/^\d+$/', $line) === 1) {
                $id = (int) $line;
            }

            if ($id > 0) {
                $result[$id] = $id;
            }
        }

        return array_values($result);
    }

    public static function sanitize_time(string $time): string
    {
        if (preg_match('/^(2[0-3]|[01]?[0-9]):([0-5][0-9])$/', $time)) {
            [$hour, $minute] = explode(':', $time, 2);
            return sprintf('%02d:%02d', (int) $hour, (int) $minute);
        }

        return '02:30';
    }

    public static function next_run_timestamp(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', self::sanitize_time($time), 2));

        $timezone = wp_timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        $next = $now->setTime($hour, $minute, 0);

        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }

    private static function sanitize_password(string $newPassword, string $existingPassword): string
    {
        $newPassword = trim($newPassword);

        if ($newPassword === '' || $newPassword === '********') {
            return $existingPassword;
        }

        return preg_replace('/\s+/', ' ', $newPassword);
    }

    private static function sanitize_local_base_path(string $path): string
    {
        $path = sanitize_text_field($path);
        $path = trim($path);
        $path = str_replace(["\0", "\r", "\n"], '', $path);

        if ($path === '') {
            return '';
        }

        return rtrim($path, "/\\");
    }
}
