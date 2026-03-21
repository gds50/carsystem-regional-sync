<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Dependency_Check_Service
{
    private const VIDEOPACK_ID = 'videopack';
    private const ONE_CLICK_ID = 'art_woocommerce_order_one_click';

    private const VIDEOPACK_PLUGIN_SLUGS = [
        'video-embed-thumbnail-generator/video-embed-thumbnail-generator.php',
        'videopack/video-embed-thumbnail-generator.php',
    ];

    private const ONE_CLICK_PLUGIN_SLUGS = [
        'art-woocommerce-order-one-click/art-woocommerce-order-one-click.php',
    ];

    /**
     * These integrations are intentionally site-specific and must never trigger dependency control.
     *
     * - WooCommerce PayKeeper Plugin
     * - WooCommerce - 1C (МойСклад, СБИС) - Data Exchange
     *
     * @var string[]
     */
    private const EXCLUDED_DEPENDENCY_IDS = [
        'woocommerce_paykeeper',
        'woocommerce_1c_data_exchange',
    ];

    private const ALERT_STATE_OPTION_KEY = 'crs_sync_dependency_alert_state';
    private const ALERT_COOLDOWN_SECONDS = 21600; // 6 hours

    public function detect_missing_for_object(string $objectType, array $remoteObject): array
    {
        $content = $this->collect_object_content($objectType, $remoteObject);

        if ($content === '') {
            return [];
        }

        $missingById = [];
        $contentLower = strtolower($content);

        foreach ($this->dependency_rules() as $rule) {
            $dependencyId = (string) ($rule['id'] ?? '');

            if ($dependencyId === '' || $this->is_dependency_excluded($dependencyId)) {
                continue;
            }

            $markers = (array) ($rule['markers'] ?? []);

            if (! $this->contains_any_marker($contentLower, $markers)) {
                continue;
            }

            if ($this->is_dependency_available($dependencyId)) {
                continue;
            }

            $missingById[$dependencyId] = (string) ($rule['label'] ?? $dependencyId);
        }

        return array_values($missingById);
    }

    public function format_missing_message(array $missingDependencies): string
    {
        if ($missingDependencies === []) {
            return 'Missing plugin dependency.';
        }

        return 'Missing plugin dependency. Install and activate: ' . implode(', ', $missingDependencies) . '.';
    }

    /**
     * @param array<int,array<string,mixed>> $dependencyIssues
     * @return string[]
     */
    public function collect_missing_from_issues(array $dependencyIssues): array
    {
        $missing = [];

        foreach ($dependencyIssues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $missingDependencies = $issue['missing_dependencies'] ?? [];
            if (! is_array($missingDependencies)) {
                continue;
            }

            foreach ($missingDependencies as $item) {
                if (! is_string($item) || $item === '') {
                    continue;
                }

                $missing[$item] = $item;
            }
        }

        return array_values($missing);
    }

    /**
     * @param string[] $missingDependencies
     */
    public function maybe_send_alert(array $missingDependencies, string $runType, int $logId): bool
    {
        $missingDependencies = array_values(array_unique(array_filter($missingDependencies, static function ($value): bool {
            return is_string($value) && $value !== '';
        })));

        if ($missingDependencies === [] || ! function_exists('wp_mail')) {
            return false;
        }

        $siteUrl = function_exists('home_url') ? (string) home_url('/') : '';
        $fingerprintSource = implode('|', $missingDependencies) . '|' . sanitize_key($runType) . '|' . $siteUrl;
        $fingerprint = sha1($fingerprintSource);

        if (! $this->should_send_alert($fingerprint)) {
            return false;
        }

        $adminEmail = function_exists('get_option') ? (string) get_option('admin_email', '') : '';
        $adminEmail = sanitize_email($adminEmail);

        if ($adminEmail === '') {
            return false;
        }

        $subject = '[CRS] Missing plugin dependency detected';
        $body = implode("\n", [
            'Carsystem Regional Sync detected missing plugin dependencies required for rendering synced content.',
            '',
            'Site: ' . ($siteUrl !== '' ? $siteUrl : 'unknown'),
            'Run type: ' . sanitize_key($runType),
            'Log ID: ' . max(0, $logId),
            '',
            'Install and activate:',
            '- ' . implode("\n- ", $missingDependencies),
            '',
            'Excluded from dependency control: WooCommerce PayKeeper Plugin, WooCommerce - 1C (МойСклад, СБИС) - Data Exchange.',
        ]);

        $sent = wp_mail($adminEmail, $subject, $body);

        if ($sent) {
            $this->remember_alert_state($fingerprint);
        }

        return (bool) $sent;
    }

    private function collect_object_content(string $objectType, array $remoteObject): string
    {
        $chunks = [];

        if ($objectType === 'product') {
            $chunks[] = (string) ($remoteObject['description'] ?? '');
            $chunks[] = (string) ($remoteObject['short_description'] ?? '');
            $chunks[] = $this->flatten_meta_data_markers($remoteObject['meta_data'] ?? []);
        }

        if ($objectType === 'product_cat') {
            $chunks[] = (string) ($remoteObject['description'] ?? '');
        }

        if ($objectType === 'page') {
            $content = $remoteObject['content'] ?? '';

            if (is_array($content)) {
                $chunks[] = is_scalar($content['raw'] ?? null)
                    ? (string) $content['raw']
                    : (string) ($content['rendered'] ?? '');
            } else {
                $chunks[] = is_scalar($content) ? (string) $content : '';
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function dependency_rules(): array
    {
        return [
            [
                'id' => self::VIDEOPACK_ID,
                'label' => 'VideoPack (videopack/video-embed-thumbnail-generator.php)',
                'markers' => [
                    '[videopack',
                    '[kgvid',
                    'kgvid',
                    'videopack',
                    '<video',
                    '<source',
                    '.mp4',
                    '.webm',
                    '.ogg',
                ],
            ],
            [
                'id' => self::ONE_CLICK_ID,
                'label' => 'Заказ/Оплата в 1 клик (art-woocommerce-order-one-click/art-woocommerce-order-one-click.php)',
                'markers' => [
                    '[art_woocommerce_order_one_click',
                    'art_woocommerce_order_one_click',
                    'one_click',
                    'one-click',
                    'buyoneclick',
                ],
            ],
        ];
    }

    private function contains_any_marker(string $contentLower, array $markers): bool
    {
        if ($contentLower === '' || $markers === []) {
            return false;
        }

        foreach ($markers as $marker) {
            $marker = strtolower((string) $marker);
            if ($marker !== '' && strpos($contentLower, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function is_dependency_available(string $dependencyId): bool
    {
        if ($dependencyId === self::VIDEOPACK_ID && $this->is_videopack_runtime_available()) {
            return true;
        }

        $slugs = [];

        if ($dependencyId === self::VIDEOPACK_ID) {
            $slugs = self::VIDEOPACK_PLUGIN_SLUGS;
        } elseif ($dependencyId === self::ONE_CLICK_ID) {
            $slugs = self::ONE_CLICK_PLUGIN_SLUGS;
        }

        return $this->is_any_plugin_active($slugs);
    }

    private function is_videopack_runtime_available(): bool
    {
        if (function_exists('shortcode_exists')) {
            if (shortcode_exists('videopack') || shortcode_exists('kgvid')) {
                return true;
            }
        }

        if (class_exists('kgvid_video_embed') || function_exists('kgvid_video_embed')) {
            return true;
        }

        return false;
    }

    /**
     * @param string[] $slugs
     */
    private function is_any_plugin_active(array $slugs): bool
    {
        if ($slugs === []) {
            return false;
        }

        if (! function_exists('is_plugin_active')) {
            $pluginFile = ABSPATH . 'wp-admin/includes/plugin.php';

            if (is_file($pluginFile)) {
                require_once $pluginFile;
            }
        }

        if (function_exists('is_plugin_active')) {
            foreach ($slugs as $slug) {
                if (is_plugin_active($slug)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_dependency_excluded(string $dependencyId): bool
    {
        return in_array($dependencyId, self::EXCLUDED_DEPENDENCY_IDS, true);
    }

    private function flatten_meta_data_markers($metaData): string
    {
        if (! is_array($metaData) || $metaData === []) {
            return '';
        }

        $chunks = [];

        foreach ($metaData as $metaItem) {
            if (! is_array($metaItem)) {
                continue;
            }

            $key = isset($metaItem['key']) ? sanitize_key((string) $metaItem['key']) : '';
            if ($key !== '') {
                $chunks[] = $key;
            }

            if (! array_key_exists('value', $metaItem)) {
                continue;
            }

            $value = $metaItem['value'];

            if (is_scalar($value)) {
                $chunks[] = (string) $value;
                continue;
            }

            $encoded = wp_json_encode($value);
            if (is_string($encoded)) {
                $chunks[] = $encoded;
            }
        }

        return implode("\n", $chunks);
    }

    private function should_send_alert(string $fingerprint): bool
    {
        $state = function_exists('get_option')
            ? get_option(self::ALERT_STATE_OPTION_KEY, [])
            : [];

        if (! is_array($state)) {
            return true;
        }

        $lastFingerprint = isset($state['fingerprint']) ? (string) $state['fingerprint'] : '';
        $lastSentAt = isset($state['sent_at']) ? (int) $state['sent_at'] : 0;

        if ($lastFingerprint !== $fingerprint) {
            return true;
        }

        if ($lastSentAt <= 0) {
            return true;
        }

        return (time() - $lastSentAt) >= self::ALERT_COOLDOWN_SECONDS;
    }

    private function remember_alert_state(string $fingerprint): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option(self::ALERT_STATE_OPTION_KEY, [
            'fingerprint' => $fingerprint,
            'sent_at' => time(),
        ], false);
    }
}
