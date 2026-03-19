<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Regionalizer
{
    public function regionalize_value(string $value, array $settings): string
    {
        $dictionary = Dictionary::parse((string) ($settings['replacement_dictionary'] ?? ''));

        if ($value === '' || $dictionary === []) {
            return $value;
        }

        return Dictionary::replace($value, $dictionary);
    }

    public function is_excluded_slug(string $slug, array $settings): bool
    {
        $excluded = array_map('strval', (array) ($settings['excluded_slugs'] ?? []));
        return in_array($slug, $excluded, true);
    }
}
