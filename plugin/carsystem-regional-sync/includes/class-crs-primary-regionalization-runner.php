<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Primary_Regionalization_Runner
{
    public const RESULT_OPTION_KEY = 'crs_sync_last_primary_regionalization';

    private const PRODUCT_BATCH_SIZE = 100;
    private const CATEGORY_BATCH_SIZE = 100;

    private Regionalizer $regionalizer;

    public function __construct(?Regionalizer $regionalizer = null)
    {
        $this->regionalizer = $regionalizer ?? new Regionalizer();
    }

    public function run(array $settings): array
    {
        $dictionary = Dictionary::parse((string) ($settings['replacement_dictionary'] ?? ''));

        $summary = [
            'status'        => 'success',
            'checked_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_count'   => 0,
            'message'       => '',
            'started_at'    => current_time('mysql', true),
            'finished_at'   => '',
        ];

        $this->process_products($settings, $dictionary, $summary);
        $this->process_categories($settings, $dictionary, $summary);

        if ($summary['error_count'] > 0) {
            $summary['status'] = 'partial';
            $summary['message'] = 'Primary regionalization finished with partial errors.';
        } else {
            $summary['message'] = 'Primary regionalization completed successfully.';
        }

        $summary['finished_at'] = current_time('mysql', true);

        update_option(self::RESULT_OPTION_KEY, $summary, false);

        return $summary;
    }

    private function process_products(array $settings, array $dictionary, array &$summary): void
    {
        $page = 1;

        do {
            $query = new \WP_Query([
                'post_type'      => 'product',
                'post_status'    => ['publish', 'draft', 'pending', 'private'],
                'posts_per_page' => self::PRODUCT_BATCH_SIZE,
                'paged'          => $page,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);

            $postIds = is_array($query->posts) ? $query->posts : [];

            foreach ($postIds as $postId) {
                $summary['checked_count']++;
                $slug = (string) get_post_field('post_name', (int) $postId);

                if ($this->regionalizer->is_excluded_slug($slug, $settings)) {
                    $summary['skipped_count']++;
                    continue;
                }

                $changed = $this->regionalize_post_meta((int) $postId, $dictionary, $summary);

                if ($changed) {
                    $summary['updated_count']++;
                } else {
                    $summary['skipped_count']++;
                }
            }

            $page++;
        } while ($postIds !== []);

        wp_reset_postdata();
    }

    private function process_categories(array $settings, array $dictionary, array &$summary): void
    {
        $offset = 0;

        do {
            $terms = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'number'     => self::CATEGORY_BATCH_SIZE,
                'offset'     => $offset,
            ]);

            if (is_wp_error($terms)) {
                $summary['error_count']++;
                return;
            }

            $termItems = is_array($terms) ? $terms : [];

            foreach ($termItems as $term) {
                if (! $term instanceof \WP_Term) {
                    continue;
                }

                $summary['checked_count']++;

                if ($this->regionalizer->is_excluded_slug($term->slug, $settings)) {
                    $summary['skipped_count']++;
                    continue;
                }

                $changed = $this->regionalize_term_meta($term->term_id, $dictionary, $summary);

                if ($changed) {
                    $summary['updated_count']++;
                } else {
                    $summary['skipped_count']++;
                }
            }

            $offset += self::CATEGORY_BATCH_SIZE;
        } while ($termItems !== []);
    }

    private function regionalize_post_meta(int $postId, array $dictionary, array &$summary): bool
    {
        $fields = [
            'seo_meta_title',
            'seo_meta_description',
        ];

        return $this->regionalize_meta_fields(
            static fn(string $key) => (string) get_post_meta($postId, $key, true),
            static fn(string $key, string $value) => update_post_meta($postId, $key, $value),
            $fields,
            $dictionary,
            $summary
        );
    }

    private function regionalize_term_meta(int $termId, array $dictionary, array &$summary): bool
    {
        $fields = [
            'seo_meta_title',
            'seo_h1',
            'seo_meta_description',
        ];

        return $this->regionalize_meta_fields(
            static fn(string $key) => (string) get_term_meta($termId, $key, true),
            static fn(string $key, string $value) => update_term_meta($termId, $key, $value),
            $fields,
            $dictionary,
            $summary
        );
    }

    private function regionalize_meta_fields(
        callable $getter,
        callable $updater,
        array $fields,
        array $dictionary,
        array &$summary
    ): bool {
        $changed = false;

        foreach ($fields as $fieldKey) {
            $currentValue = (string) $getter($fieldKey);

            if ($currentValue === '' || $dictionary === []) {
                continue;
            }

            $newValue = $this->regionalizer->regionalize_with_dictionary($currentValue, $dictionary);

            if ($newValue === $currentValue) {
                continue;
            }

            $result = $updater($fieldKey, $newValue);

            if ($result === false) {
                $summary['error_count']++;
                continue;
            }

            $changed = true;
        }

        return $changed;
    }
}
