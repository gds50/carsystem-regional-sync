<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Menu_Sync_Service
{
    private const TARGET_MENU_LOCATION = 'top';
    private const TARGET_MENU_NAME = 'Основное';
    private const MENU_HASH_OPTION_KEY = 'crs_sync_top_menu_hash';

    public function sync_top_menu(array $settings): bool
    {
        $sourceUrl = esc_url_raw((string) ($settings['source_url'] ?? ''));

        if ($sourceUrl === '' || ! wp_http_validate_url($sourceUrl)) {
            throw new \RuntimeException('Top menu sync failed: invalid source URL.');
        }

        $html = $this->fetch_source_html($sourceUrl);
        $items = $this->extract_top_menu_items($html);

        if ($items === []) {
            throw new \RuntimeException('Top menu sync failed: source top-menu markup not found.');
        }

        $normalizedItems = $this->normalize_menu_items($items, $settings, false);
        $encoded = wp_json_encode($normalizedItems);
        $newHash = hash('sha256', is_string($encoded) ? $encoded : '');
        $currentHash = (string) get_option(self::MENU_HASH_OPTION_KEY, '');
        $menuId = $this->ensure_target_menu();
        $localItems = $this->read_local_menu_tree($menuId);
        $isLocalInSync = $this->menu_trees_equal($normalizedItems, $localItems);

        if ($currentHash === $newHash && $isLocalInSync) {
            $this->ensure_menu_location($menuId);
            return false;
        }

        $this->rebuild_menu($menuId, $normalizedItems);
        $this->ensure_menu_location($menuId);
        update_option(self::MENU_HASH_OPTION_KEY, $newHash, false);

        return true;
    }

    private function read_local_menu_tree(int $menuId): array
    {
        if ($menuId <= 0) {
            return [];
        }

        $items = wp_get_nav_menu_items($menuId, [
            'post_status' => 'publish',
        ]);

        if (! is_array($items) || $items === []) {
            return [];
        }

        $byParent = [];

        foreach ($items as $item) {
            if (! $item instanceof \WP_Post) {
                continue;
            }

            $parentId = (int) get_post_meta((int) $item->ID, '_menu_item_menu_item_parent', true);
            $byParent[$parentId][] = $item;
        }

        foreach ($byParent as $parentId => $list) {
            usort($list, static function (\WP_Post $left, \WP_Post $right): int {
                return (int) $left->menu_order <=> (int) $right->menu_order;
            });
            $byParent[$parentId] = $list;
        }

        return $this->build_tree_from_local_items($byParent, 0);
    }

    private function build_tree_from_local_items(array $byParent, int $parentId): array
    {
        $result = [];
        $children = $byParent[$parentId] ?? [];

        foreach ($children as $item) {
            if (! $item instanceof \WP_Post) {
                continue;
            }

            $url = (string) get_post_meta((int) $item->ID, '_menu_item_url', true);

            $result[] = [
                'title' => sanitize_text_field((string) $item->post_title),
                'url' => $this->normalize_compare_url($url),
                'children' => $this->build_tree_from_local_items($byParent, (int) $item->ID),
            ];
        }

        return $result;
    }

    private function menu_trees_equal(array $expected, array $actual): bool
    {
        $normalize = function (array $items) use (&$normalize): array {
            $result = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $title = sanitize_text_field((string) ($item['title'] ?? ''));

                if ($title === '') {
                    continue;
                }

                $result[] = [
                    'title' => $title,
                    'url' => $this->normalize_compare_url((string) ($item['url'] ?? '')),
                    'children' => $normalize((array) ($item['children'] ?? [])),
                ];
            }

            return $result;
        };

        return $normalize($expected) === $normalize($actual);
    }

    private function normalize_compare_url(string $url): string
    {
        $url = trim($url);

        if ($url === '' || strpos($url, '#') === 0) {
            return $url;
        }

        $parts = wp_parse_url($url);

        if (! is_array($parts)) {
            return esc_url_raw($url);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? (string) $parts['query'] : '';
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;

        if ($scheme === '' || $host === '') {
            return esc_url_raw($url);
        }

        if ($path === '') {
            $path = '/';
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $normalized = $scheme . '://' . $host;

        if ($port > 0 && $port !== 80 && $port !== 443) {
            $normalized .= ':' . $port;
        }

        $normalized .= $path;

        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return esc_url_raw($normalized);
    }

    private function fetch_source_html(string $sourceUrl): string
    {
        $response = wp_remote_get(trailingslashit($sourceUrl), [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'text/html',
            ],
            'user-agent' => 'CarsystemRegionalSync/' . CRS_SYNC_VERSION . '; ' . home_url('/'),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Top menu sync request failed: ' . sanitize_text_field($response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Top menu sync request failed: HTTP ' . $code);
        }

        $body = (string) wp_remote_retrieve_body($response);

        if (trim($body) === '') {
            throw new \RuntimeException('Top menu sync request returned empty HTML.');
        }

        return $body;
    }

    private function extract_top_menu_items(string $html): array
    {
        if (! class_exists('\DOMDocument') || ! class_exists('\DOMXPath')) {
            return [];
        }

        $dom = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        if (! $loaded) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $query = "//div[contains(concat(' ', normalize-space(@class), ' '), ' top-menu ')]//ul[@id='top-menu']";
        $nodes = $xpath->query($query);

        if (! $nodes instanceof \DOMNodeList || $nodes->length === 0) {
            $nodes = $xpath->query("//ul[@id='top-menu']");
        }

        if (! $nodes instanceof \DOMNodeList || $nodes->length === 0) {
            return [];
        }

        $root = $nodes->item(0);

        if (! $root instanceof \DOMElement) {
            return [];
        }

        return $this->parse_menu_ul($root);
    }

    private function parse_menu_ul(\DOMElement $ul): array
    {
        $items = [];

        foreach ($ul->childNodes as $childNode) {
            if (! $childNode instanceof \DOMElement || strtolower($childNode->tagName) !== 'li') {
                continue;
            }

            $item = $this->parse_menu_li($childNode);

            if ($item === null) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    private function parse_menu_li(\DOMElement $li): ?array
    {
        $title = '';
        $url = '';
        $children = [];

        foreach ($li->childNodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if ($tag === 'a' && $title === '') {
                $title = trim(wp_strip_all_tags((string) $node->textContent));
                $url = trim((string) $node->getAttribute('href'));
                continue;
            }

            if ($tag === 'span' && $title === '') {
                $title = trim(wp_strip_all_tags((string) $node->textContent));
                continue;
            }

            if ($tag === 'ul') {
                $children = $this->parse_menu_ul($node);
            }
        }

        if ($title === '') {
            return null;
        }

        return [
            'title' => $title,
            'url' => $url,
            'classes' => trim((string) $li->getAttribute('class')),
            'children' => $children,
        ];
    }

    private function normalize_menu_items(array $items, array $settings, bool $preserveSourceUrls): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = sanitize_text_field((string) ($item['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $classes = (string) ($item['classes'] ?? '');

            $normalized[] = [
                'title' => $title,
                'url' => $this->normalize_menu_url((string) ($item['url'] ?? ''), $classes, $settings, $preserveSourceUrls),
                'children' => $this->normalize_menu_items(
                    (array) ($item['children'] ?? []),
                    $settings,
                    $this->should_preserve_source_urls_for_children($title, $classes, $preserveSourceUrls)
                ),
            ];
        }

        return $normalized;
    }

    private function normalize_menu_url(string $url, string $classes, array $settings, bool $preserveSourceUrls): string
    {
        $url = trim($url);
        $sourceUrl = untrailingslashit((string) ($settings['source_url'] ?? ''));
        $localUrl = untrailingslashit(home_url('/'));
        $sourceHost = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

        if ($url === '') {
            // В source markup текущий пункт может рендериться как <span class="removed-link">...</span>.
            // Для таких случаев восстанавливаем ссылку на source home.
            if ($sourceUrl !== '' && strpos($classes, 'menu-item-home') !== false) {
                return esc_url_raw(trailingslashit($sourceUrl));
            }

            return esc_url_raw(trailingslashit($localUrl));
        }

        if (strpos($url, '#') === 0) {
            return $url;
        }

        if ($preserveSourceUrls) {
            if (strpos($url, '/') === 0 && strpos($url, '//') !== 0 && $sourceUrl !== '') {
                return esc_url_raw($sourceUrl . $url);
            }

            return esc_url_raw($url);
        }

        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return esc_url_raw($localUrl . $url);
        }

        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $urlPath = (string) parse_url($url, PHP_URL_PATH);
        $urlQuery = (string) parse_url($url, PHP_URL_QUERY);

        if ($sourceHost !== '' && $urlHost === $sourceHost && $urlPath !== '') {
            $localized = $localUrl . $urlPath;

            if ($urlQuery !== '') {
                $localized .= '?' . $urlQuery;
            }

            return esc_url_raw($localized);
        }

        return esc_url_raw($url);
    }

    private function should_preserve_source_urls_for_children(string $title, string $classes, bool $parentPreserve): bool
    {
        if ($parentPreserve) {
            return true;
        }

        $normalizedTitle = function_exists('mb_strtolower')
            ? mb_strtolower(trim($title), 'UTF-8')
            : strtolower(trim($title));

        if ($normalizedTitle === 'город') {
            return true;
        }

        return strpos($classes, 'menu-item-6108') !== false;
    }

    private function ensure_target_menu(): int
    {
        $menu = wp_get_nav_menu_object(self::TARGET_MENU_NAME);

        if ($menu instanceof \WP_Term) {
            return (int) $menu->term_id;
        }

        $locations = get_theme_mod('nav_menu_locations', []);

        if (is_array($locations)) {
            $assignedMenuId = (int) ($locations[self::TARGET_MENU_LOCATION] ?? 0);

            if ($assignedMenuId > 0) {
                $assignedMenu = wp_get_nav_menu_object($assignedMenuId);

                if ($assignedMenu instanceof \WP_Term) {
                    return (int) $assignedMenu->term_id;
                }
            }
        }

        $menuId = wp_create_nav_menu(self::TARGET_MENU_NAME);

        if (is_wp_error($menuId)) {
            throw new \RuntimeException('Top menu sync failed: unable to create local menu.');
        }

        return (int) $menuId;
    }

    private function rebuild_menu(int $menuId, array $items): void
    {
        $existing = wp_get_nav_menu_items($menuId, [
            'post_status' => 'any',
        ]);

        if (is_array($existing)) {
            foreach ($existing as $existingItem) {
                if (! $existingItem instanceof \WP_Post) {
                    continue;
                }

                wp_delete_post((int) $existingItem->ID, true);
            }
        }

        $position = 1;
        $this->insert_menu_items($menuId, $items, 0, $position);
    }

    private function insert_menu_items(int $menuId, array $items, int $parentItemId, int &$position): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = sanitize_text_field((string) ($item['title'] ?? ''));
            $url = esc_url_raw((string) ($item['url'] ?? ''));

            if ($title === '') {
                continue;
            }

            if ($url === '') {
                $url = home_url('/');
            }

            $itemId = wp_update_nav_menu_item($menuId, 0, [
                'menu-item-title' => $title,
                'menu-item-url' => $url,
                'menu-item-status' => 'publish',
                'menu-item-type' => 'custom',
                'menu-item-parent-id' => $parentItemId,
                'menu-item-position' => $position,
            ]);

            if (is_wp_error($itemId) || (int) $itemId <= 0) {
                throw new \RuntimeException('Top menu sync failed: unable to create menu item.');
            }

            $position++;
            $this->insert_menu_items($menuId, (array) ($item['children'] ?? []), (int) $itemId, $position);
        }
    }

    private function ensure_menu_location(int $menuId): void
    {
        $locations = get_theme_mod('nav_menu_locations', []);

        if (! is_array($locations)) {
            $locations = [];
        }

        if ((int) ($locations[self::TARGET_MENU_LOCATION] ?? 0) === $menuId) {
            return;
        }

        $locations[self::TARGET_MENU_LOCATION] = $menuId;
        set_theme_mod('nav_menu_locations', $locations);
    }
}
