<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Security
{
    public static function can_manage_plugin(): bool
    {
        $user = wp_get_current_user();

        return is_user_logged_in()
            && current_user_can('manage_options')
            && isset($user->user_login)
            && $user->user_login === 'caradmin';
    }

    public static function assert_admin_access(): void
    {
        if (! self::can_manage_plugin()) {
            wp_die(esc_html__('Access denied.', 'carsystem-regional-sync'), 403);
        }
    }

    public static function assert_admin_action(string $nonceAction): void
    {
        self::assert_admin_access();
        check_admin_referer($nonceAction);
    }
}
