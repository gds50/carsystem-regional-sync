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
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_admin']);
    }

    public function register_settings(): void
    {
        Settings::register();
    }

    public function register_admin(): void
    {
        (new Admin_Page())->register();
    }
}
