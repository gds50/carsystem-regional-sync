<?php

use CRS\Media_Sync_Service;

function crs_media_sync_call_private(Media_Sync_Service $service, string $method, array $args = [])
{
    $reflection = new ReflectionClass($service);
    $privateMethod = $reflection->getMethod($method);
    $privateMethod->setAccessible(true);

    return $privateMethod->invokeArgs($service, $args);
}

function crs_run_media_sync_tests(CRS_Unit_Test_Runner $runner): void
{
    $service = new Media_Sync_Service();

    $content = '<img src="https://carsystem.su/wp-content/uploads/2026/03/a.jpg?ver=1">' .
        '<source data-src="https://carsystem.su/wp-content/uploads/2026/03/v.mp4#frag">' .
        '<a data-alt_link="https://carsystem.su/wp-content/uploads/2026/03/b.png?x=1"></a>' .
        ' Raw: https:\/\/carsystem.su\/wp-content\/uploads\/2026\/03\/c.webp?cache=1';

    $urls = crs_media_sync_call_private($service, 'extract_media_urls_from_content', [$content]);

    $runner->assertSame(
        [
            'https://carsystem.su/wp-content/uploads/2026/03/a.jpg',
            'https://carsystem.su/wp-content/uploads/2026/03/v.mp4',
            'https://carsystem.su/wp-content/uploads/2026/03/b.png',
            'https://carsystem.su/wp-content/uploads/2026/03/c.webp',
        ],
        $urls,
        'Media URL extraction supports html attributes, escaped text URLs and URL normalization'
    );

    $normalizedContent = crs_media_sync_call_private($service, 'normalize_videopack_markup', [
        '<p><strong>ВИДЕО:</strong></p><div class="kgvid_gallerywrapper">old</div><p><strong>ВИДЕО:</strong></p>',
        [101, 102],
    ]);

    $runner->assertTrue(
        strpos($normalizedContent, '[videopack gallery="true" gallery_include="101,102"') !== false,
        'VideoPack normalization rewrites kgvid markup to gallery shortcode with local attachment IDs'
    );

    $runner->assertFalse(
        substr_count($normalizedContent, '<p><strong>ВИДЕО:</strong></p>') > 1,
        'VideoPack normalization keeps a single trailing VIDEO heading'
    );

    $runner->assertTrue(
        (bool) crs_media_sync_call_private($service, 'should_retry_media_error', ['http_request_failed', 'Media download failed: cURL error 28: Operation timed out']),
        'Retry policy retries transient network errors'
    );

    $runner->assertFalse(
        (bool) crs_media_sync_call_private($service, 'should_retry_media_error', ['upload_error', 'Media sideload failed: Sorry, you are not allowed to upload this file type.']),
        'Retry policy does not retry permanent validation/file-type errors'
    );
}
