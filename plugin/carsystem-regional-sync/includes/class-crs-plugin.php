<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        // Milestone 1 bootstrap only:
        // keep a stable initialization point for future hooks/services.
    }
}
