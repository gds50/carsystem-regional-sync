<?php

use CRS\Dictionary;
use CRS\Settings;

function crs_run_settings_dictionary_tests(CRS_Unit_Test_Runner $runner): void
{
    $runner->assertSame(
        "Москва => Тюмень\nСанкт-Петербург => Омск",
        Settings::sanitize_dictionary(" Москва=>Тюмень \ninvalid\nСанкт-Петербург => Омск\nfoo =>   "),
        'sanitize_dictionary keeps only valid from=>to pairs'
    );

    $runner->assertSame(
        ['gde-kupit', 'privacy-policy', 'new-item'],
        Settings::sanitize_excluded_slugs("gde-kupit\nprivacy-policy\nnew item\nnew item"),
        'sanitize_excluded_slugs normalizes, deduplicates and keeps order'
    );

    $runner->assertSame(
        [5677, 991, 123],
        Settings::sanitize_excluded_product_remote_ids("post=5677\nhttps://carsystem.su/wp-admin/post.php?post=991&action=edit\n123\nfoo"),
        'sanitize_excluded_product_remote_ids parses post=ID, URL and plain numeric values'
    );

    $runner->assertSame(
        '02:30',
        Settings::sanitize_time('99:99'),
        'sanitize_time falls back to default for invalid values'
    );

    $runner->assertSame(
        '09:05',
        Settings::sanitize_time('9:05'),
        'sanitize_time normalizes single digit hour'
    );

    $sanitized = Settings::sanitize([
        'use_local_media_copy' => '1',
        'source_local_base_path' => " /home/g/gds50/carsystem.su/public_html/ \n",
        'excluded_product_remote_ids' => "post=5677\n5677\n?post=991",
    ]);

    $runner->assertSame(
        1,
        (int) ($sanitized['use_local_media_copy'] ?? 0),
        'sanitize keeps local media copy toggle as enabled flag'
    );

    $runner->assertSame(
        '/home/g/gds50/carsystem.su/public_html',
        (string) ($sanitized['source_local_base_path'] ?? ''),
        'sanitize trims and normalizes source local base path'
    );

    $runner->assertSame(
        [5677, 991],
        array_map('intval', (array) ($sanitized['excluded_product_remote_ids'] ?? [])),
        'sanitize keeps unique excluded remote product IDs'
    );

    $pairs = Dictionary::parse("москва => тюмень\nмоск => омск");
    $keys = array_keys($pairs);

    $runner->assertSame(
        ['москва', 'моск'],
        $keys,
        'Dictionary::parse sorts by key length (longer first)'
    );

    $runner->assertSame(
        'тюмень и омск',
        Dictionary::replace('москва и моск', $pairs),
        'Dictionary::replace applies ordered replacements'
    );

    $runner->assertTrue(
        Dictionary::is_excluded_slug('  New Item ', ['new item', 'other']),
        'Dictionary::is_excluded_slug compares normalized slugs'
    );

    $runner->assertFalse(
        Dictionary::is_excluded_slug('', ['new-item']),
        'Dictionary::is_excluded_slug ignores empty values'
    );
}
