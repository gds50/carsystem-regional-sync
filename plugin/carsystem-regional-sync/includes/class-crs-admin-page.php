<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Admin_Page
{
    private const MENU_SLUG = 'crs-sync';

    public function register(): void
    {
        if (! Security::can_manage_plugin()) {
            return;
        }

        add_menu_page(
            __('Carsystem Sync', 'carsystem-regional-sync'),
            __('Carsystem Sync', 'carsystem-regional-sync'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-update',
            58
        );
    }

    public function render(): void
    {
        Security::assert_admin_access();

        require CRS_SYNC_PLUGIN_DIR . '/templates/admin-page.php';
    }

    public static function page_url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::MENU_SLUG], $args), admin_url('admin.php'));
    }
}
