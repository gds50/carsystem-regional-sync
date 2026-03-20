<?php

use CRS\Dependency_Check_Service;

function crs_run_dependency_check_tests(CRS_Unit_Test_Runner $runner): void
{
    $service = new Dependency_Check_Service();

    $missingForProduct = $service->detect_missing_for_object('product', [
        'description' => 'Видео обзор [videopack id="123"]',
    ]);

    $runner->assertSame(
        ['VideoPack (videopack/video-embed-thumbnail-generator.php)'],
        $missingForProduct,
        'Dependency check detects missing VideoPack for product video markers'
    );

    $missingForPage = $service->detect_missing_for_object('page', [
        'content' => [
            'rendered' => '<video src="https://carsystem.su/wp-content/uploads/demo.mp4"></video>',
        ],
    ]);

    $runner->assertSame(
        ['VideoPack (videopack/video-embed-thumbnail-generator.php)'],
        $missingForPage,
        'Dependency check detects missing VideoPack for page media markers'
    );

    $message = $service->format_missing_message($missingForProduct);

    $runner->assertTrue(
        strpos($message, 'Install and activate: VideoPack') !== false,
        'Dependency check builds actionable install message'
    );

    $runner->assertSame(
        [],
        $service->detect_missing_for_object('product', ['description' => 'Просто текст без видео']),
        'Dependency check returns empty list when no video markers exist'
    );
}
