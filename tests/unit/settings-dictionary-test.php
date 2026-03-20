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
        '02:30',
        Settings::sanitize_time('99:99'),
        'sanitize_time falls back to default for invalid values'
    );

    $runner->assertSame(
        '09:05',
        Settings::sanitize_time('9:05'),
        'sanitize_time normalizes single digit hour'
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
