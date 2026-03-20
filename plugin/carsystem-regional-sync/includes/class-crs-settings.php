<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings
{
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
            'source_url'               => esc_url_raw($input['source_url'] ?? $defaults['source_url']),
            'api_username'             => sanitize_user($input['api_username'] ?? ''),
            'api_application_password' => self::sanitize_password($input['api_application_password'] ?? '', $current['api_application_password'] ?? ''),
            'region'                   => sanitize_text_field($input['region'] ?? ''),
            'city'                     => sanitize_text_field($input['city'] ?? ''),
            'area'                     => sanitize_text_field($input['area'] ?? ''),
            'replacement_dictionary'   => self::sanitize_dictionary($input['replacement_dictionary'] ?? ''),
            'partner_name'             => sanitize_text_field($input['partner_name'] ?? ''),
            'partner_phone'            => sanitize_text_field($input['partner_phone'] ?? ''),
            'partner_email'            => sanitize_email($input['partner_email'] ?? ''),
            'partner_address'          => sanitize_textarea_field($input['partner_address'] ?? ''),
            'excluded_slugs'           => self::sanitize_excluded_slugs($input['excluded_slugs'] ?? []),
            'auto_sync_enabled'        => empty($input['auto_sync_enabled']) ? 0 : 1,
            'sync_time'                => self::sanitize_time($input['sync_time'] ?? $defaults['sync_time']),
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
}
