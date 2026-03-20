<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Dictionary
{
    public static function parse(string $raw): array
    {
        $raw = Settings::sanitize_dictionary($raw);
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $pairs = [];

        foreach ($lines as $line) {
            [$from, $to] = array_map('trim', explode('=>', $line, 2));
            $pairs[$from] = $to;
        }

        uksort($pairs, static fn(string $a, string $b) => self::length($b) <=> self::length($a));

        return $pairs;
    }

    public static function replace(string $value, array $pairs): string
    {
        $result = $value;

        foreach ($pairs as $from => $to) {
            $result = str_replace($from, $to, $result);
        }

        return $result;
    }

    public static function is_excluded_slug(string $slug, array $excludedSlugs): bool
    {
        $normalizedSlug = sanitize_title($slug);

        if ($normalizedSlug === '') {
            return false;
        }

        $normalizedExcluded = Settings::sanitize_excluded_slugs($excludedSlugs);

        return in_array($normalizedSlug, $normalizedExcluded, true);
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}
