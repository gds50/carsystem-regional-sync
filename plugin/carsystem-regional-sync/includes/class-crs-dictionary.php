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

        uksort($pairs, static fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

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
}
