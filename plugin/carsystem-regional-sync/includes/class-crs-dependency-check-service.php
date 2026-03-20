<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Dependency_Check_Service
{
    private const VIDEOPACK_PLUGIN_SLUGS = [
        'video-embed-thumbnail-generator/video-embed-thumbnail-generator.php',
        'videopack/video-embed-thumbnail-generator.php',
    ];

    public function detect_missing_for_object(string $objectType, array $remoteObject): array
    {
        $content = $this->collect_object_content($objectType, $remoteObject);

        if ($content === '') {
            return [];
        }

        $missing = [];

        if ($this->contains_video_markers($content) && ! $this->is_videopack_available()) {
            $missing[] = 'VideoPack (videopack/video-embed-thumbnail-generator.php)';
        }

        return array_values(array_unique($missing));
    }

    public function format_missing_message(array $missingDependencies): string
    {
        if ($missingDependencies === []) {
            return 'Missing plugin dependency.';
        }

        return 'Missing plugin dependency. Install and activate: ' . implode(', ', $missingDependencies) . '.';
    }

    private function collect_object_content(string $objectType, array $remoteObject): string
    {
        $chunks = [];

        if ($objectType === 'product') {
            $chunks[] = (string) ($remoteObject['description'] ?? '');
            $chunks[] = (string) ($remoteObject['short_description'] ?? '');
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

    private function contains_video_markers(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        $needles = [
            '[videopack',
            '[kgvid',
            'kgvid',
            'videopack',
            '<video',
            '<source',
            '.mp4',
            '.webm',
            '.ogg',
        ];

        $haystack = strtolower($content);

        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function is_videopack_available(): bool
    {
        if (function_exists('shortcode_exists')) {
            if (shortcode_exists('videopack') || shortcode_exists('kgvid')) {
                return true;
            }
        }

        if (class_exists('kgvid_video_embed') || function_exists('kgvid_video_embed')) {
            return true;
        }

        if (! function_exists('is_plugin_active')) {
            $pluginFile = ABSPATH . 'wp-admin/includes/plugin.php';

            if (is_file($pluginFile)) {
                require_once $pluginFile;
            }
        }

        if (function_exists('is_plugin_active')) {
            foreach (self::VIDEOPACK_PLUGIN_SLUGS as $slug) {
                if (is_plugin_active($slug)) {
                    return true;
                }
            }
        }

        return false;
    }
}
