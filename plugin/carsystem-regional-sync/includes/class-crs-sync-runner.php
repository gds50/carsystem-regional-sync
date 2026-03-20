<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Runner
{
    private const CATEGORY_OBJECT_TYPE = 'product_cat';
    private const CATEGORY_TAXONOMY = 'product_cat';
    private const CATEGORY_PER_PAGE = 100;

    private const PRODUCT_OBJECT_TYPE = 'product';
    private const PRODUCT_POST_TYPE = 'product';
    private const PRODUCT_PER_PAGE = 100;

    private const PAGE_OBJECT_TYPE = 'page';
    private const PAGE_POST_TYPE = 'page';
    private const PAGE_PER_PAGE = 100;

    private const MAX_PAGES = 1000;
    private const REMOTE_ID_META_KEY = '_crs_remote_id';
    private const UNPUBLISHED_META_KEY = '_crs_sync_unpublished';

    private Api_Client $client;
    private Logger $logger;
    private ?Regionalizer $regionalizer;
    private ?Lock $lock;
    private Sync_Map_Repository $mapRepository;

    public function __construct(
        Api_Client $client,
        Logger $logger,
        ?Regionalizer $regionalizer = null,
        ?Lock $lock = null,
        ?Sync_Map_Repository $mapRepository = null
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->regionalizer = $regionalizer ?? new Regionalizer();
        $this->lock = $lock ?? new Lock();
        $this->mapRepository = $mapRepository ?? new Sync_Map_Repository();
    }

    public static function make(): self
    {
        return new self(new Api_Client(Settings::get()), new Logger());
    }

    public function test_connection(): void
    {
        $logId = $this->logger->start('connection_test');

        try {
            $result = $this->client->test_connection();
            $this->logger->finish($logId, [
                'status'        => 'success',
                'checked_count' => 1,
                'message'       => 'Connection OK for user: ' . sanitize_text_field((string) ($result['slug'] ?? $result['name'] ?? 'unknown')),
            ]);
        } catch (\Throwable $e) {
            $this->logger->finish($logId, [
                'status'      => 'error',
                'error_count' => 1,
                'message'     => $e->getMessage(),
            ]);
        }
    }

    public function run_primary_regionalization(): void
    {
        $logId = $this->logger->start('primary_regionalization');

        // TODO: implement real local products/categories traversal and allowed SEO field updates.
        $this->logger->finish($logId, [
            'status'        => 'success',
            'checked_count' => 0,
            'updated_count' => 0,
            'message'       => 'Primary regionalization skeleton executed. Implementation pending.',
        ]);
    }

    public function run_sync(string $runType): void
    {
        if (! $this->lock->acquire($runType)) {
            return;
        }

        $logId = $this->logger->start($runType);

        try {
            $settings = Settings::get();

            $categorySummary = $this->run_categories_sync($settings);
            $productSummary = $this->run_products_sync($settings);
            $pageSummary = $this->run_pages_sync($settings);
            $summary = $this->merge_sync_summaries($categorySummary, $productSummary, $pageSummary);

            $this->logger->finish($logId, $summary);
        } catch (\Throwable $e) {
            $this->logger->finish($logId, [
                'status'      => 'error',
                'error_count' => 1,
                'message'     => $e->getMessage(),
            ]);
        } finally {
            $this->lock->release();
        }
    }

    private function merge_sync_summaries(array $categorySummary, array $productSummary, array $pageSummary): array
    {
        $status = 'success';

        if (
            (string) ($categorySummary['status'] ?? 'success') === 'error'
            || (string) ($productSummary['status'] ?? 'success') === 'error'
            || (string) ($pageSummary['status'] ?? 'success') === 'error'
        ) {
            $status = 'error';
        } elseif (
            (string) ($categorySummary['status'] ?? 'success') === 'partial'
            || (string) ($productSummary['status'] ?? 'success') === 'partial'
            || (string) ($pageSummary['status'] ?? 'success') === 'partial'
        ) {
            $status = 'partial';
        }

        return [
            'status'        => $status,
            'checked_count' => (int) ($categorySummary['checked_count'] ?? 0) + (int) ($productSummary['checked_count'] ?? 0) + (int) ($pageSummary['checked_count'] ?? 0),
            'updated_count' => (int) ($categorySummary['updated_count'] ?? 0) + (int) ($productSummary['updated_count'] ?? 0) + (int) ($pageSummary['updated_count'] ?? 0),
            'created_count' => (int) ($categorySummary['created_count'] ?? 0) + (int) ($productSummary['created_count'] ?? 0) + (int) ($pageSummary['created_count'] ?? 0),
            'skipped_count' => (int) ($categorySummary['skipped_count'] ?? 0) + (int) ($productSummary['skipped_count'] ?? 0) + (int) ($pageSummary['skipped_count'] ?? 0),
            'error_count'   => (int) ($categorySummary['error_count'] ?? 0) + (int) ($productSummary['error_count'] ?? 0) + (int) ($pageSummary['error_count'] ?? 0),
            'message'       => 'Categories, products and pages sync completed.',
        ];
    }

    private function run_categories_sync(array $settings): array
    {
        $summary = [
            'status'        => 'success',
            'checked_count' => 0,
            'updated_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'error_count'   => 0,
            'message'       => '',
        ];

        $remoteCategories = $this->fetch_all_remote_categories();
        $seenRemoteIds = [];
        $pendingParentAssignments = [];

        foreach ($remoteCategories as $remoteCategory) {
            $summary['checked_count']++;

            try {
                $result = $this->sync_single_category(
                    $remoteCategory,
                    $settings,
                    $seenRemoteIds,
                    $pendingParentAssignments
                );

                if ($result === 'created') {
                    $summary['created_count']++;
                    continue;
                }

                if ($result === 'updated') {
                    $summary['updated_count']++;
                    continue;
                }

                $summary['skipped_count']++;
            } catch (\Throwable $e) {
                $summary['error_count']++;
                $this->mark_category_sync_error($remoteCategory, $e->getMessage());
            }
        }

        $parentSyncResult = $this->apply_deferred_parent_assignments($pendingParentAssignments);
        $summary['updated_count'] += (int) ($parentSyncResult['updated'] ?? 0);
        $summary['error_count'] += (int) ($parentSyncResult['errors'] ?? 0);

        $summary['updated_count'] += $this->apply_category_unpublish_logic($seenRemoteIds);

        if ($summary['error_count'] > 0) {
            $summary['status'] = 'partial';
            $summary['message'] = 'Categories sync finished with partial errors.';
        } else {
            $summary['message'] = 'Categories sync completed successfully.';
        }

        return $summary;
    }

    private function run_products_sync(array $settings): array
    {
        $summary = [
            'status'        => 'success',
            'checked_count' => 0,
            'updated_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'error_count'   => 0,
            'message'       => '',
        ];

        $dictionary = Dictionary::parse((string) ($settings['replacement_dictionary'] ?? ''));
        $remoteProducts = $this->fetch_all_remote_products();
        $seenRemoteIds = [];

        foreach ($remoteProducts as $remoteProduct) {
            $summary['checked_count']++;

            try {
                $result = $this->sync_single_product($remoteProduct, $settings, $dictionary, $seenRemoteIds);

                if ($result === 'created') {
                    $summary['created_count']++;
                    continue;
                }

                if ($result === 'updated') {
                    $summary['updated_count']++;
                    continue;
                }

                $summary['skipped_count']++;
            } catch (\Throwable $e) {
                $summary['error_count']++;
                $this->mark_product_sync_error($remoteProduct, $e->getMessage());
            }
        }

        $summary['updated_count'] += $this->apply_product_unpublish_logic($seenRemoteIds);

        if ($summary['error_count'] > 0) {
            $summary['status'] = 'partial';
            $summary['message'] = 'Products sync finished with partial errors.';
        } else {
            $summary['message'] = 'Products sync completed successfully.';
        }

        return $summary;
    }

    private function run_pages_sync(array $settings): array
    {
        $summary = [
            'status'        => 'success',
            'checked_count' => 0,
            'updated_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'error_count'   => 0,
            'message'       => '',
        ];

        $remotePages = $this->fetch_all_remote_pages();
        $seenRemoteIds = [];

        foreach ($remotePages as $remotePage) {
            $summary['checked_count']++;

            try {
                $result = $this->sync_single_page($remotePage, $settings, $seenRemoteIds);

                if ($result === 'created') {
                    $summary['created_count']++;
                    continue;
                }

                if ($result === 'updated') {
                    $summary['updated_count']++;
                    continue;
                }

                $summary['skipped_count']++;
            } catch (\Throwable $e) {
                $summary['error_count']++;
                $this->mark_page_sync_error($remotePage, $e->getMessage());
            }
        }

        $summary['updated_count'] += $this->apply_page_unpublish_logic($seenRemoteIds);

        if ($summary['error_count'] > 0) {
            $summary['status'] = 'partial';
            $summary['message'] = 'Pages sync finished with partial errors.';
        } else {
            $summary['message'] = 'Pages sync completed successfully.';
        }

        return $summary;
    }

    private function fetch_all_remote_categories(): array
    {
        $page = 1;
        $categories = [];

        while ($page <= self::MAX_PAGES) {
            $batch = $this->client->fetch_categories($page, self::CATEGORY_PER_PAGE);

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $categories[] = $item;
                }
            }

            if (count($batch) < self::CATEGORY_PER_PAGE) {
                break;
            }

            $page++;
        }

        usort($categories, static function (array $left, array $right): int {
            $leftParent = (int) ($left['parent'] ?? 0);
            $rightParent = (int) ($right['parent'] ?? 0);

            if ($leftParent === $rightParent) {
                return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            }

            return $leftParent <=> $rightParent;
        });

        return $categories;
    }

    private function fetch_all_remote_products(): array
    {
        $page = 1;
        $products = [];

        while ($page <= self::MAX_PAGES) {
            $batch = $this->client->fetch_products($page, self::PRODUCT_PER_PAGE);

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $products[] = $item;
                }
            }

            if (count($batch) < self::PRODUCT_PER_PAGE) {
                break;
            }

            $page++;
        }

        return $products;
    }

    private function fetch_all_remote_pages(): array
    {
        $page = 1;
        $pages = [];

        while ($page <= self::MAX_PAGES) {
            $batch = $this->client->fetch_pages($page, self::PAGE_PER_PAGE);

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $pages[] = $item;
                }
            }

            if (count($batch) < self::PAGE_PER_PAGE) {
                break;
            }

            $page++;
        }

        return $pages;
    }

    private function sync_single_category(
        array $remoteCategory,
        array $settings,
        array &$seenRemoteIds,
        array &$pendingParentAssignments
    ): string {
        $remoteId = (int) ($remoteCategory['id'] ?? 0);

        if ($remoteId <= 0) {
            throw new \RuntimeException('Remote category payload has invalid id.');
        }

        $slug = sanitize_title((string) ($remoteCategory['slug'] ?? ''));
        $name = sanitize_text_field((string) ($remoteCategory['name'] ?? ''));

        if ($slug === '') {
            $slug = sanitize_title($name);
        }

        if ($slug === '') {
            throw new \RuntimeException('Remote category payload has empty slug.');
        }

        $seenRemoteIds[] = $remoteId;
        $seenRemoteIds = array_values(array_unique($seenRemoteIds));

        if ($this->regionalizer->is_excluded_slug($slug, $settings)) {
            return 'skipped';
        }

        $mapping = $this->mapRepository->find_by_remote(self::CATEGORY_OBJECT_TYPE, $remoteId);
        $localTerm = $this->resolve_local_term($mapping, $slug);
        $localTermId = $localTerm instanceof \WP_Term ? (int) $localTerm->term_id : 0;

        $payload = $this->normalize_category_payload($remoteCategory);
        $encodedPayload = wp_json_encode($payload);
        $payloadHash = hash('sha256', is_string($encodedPayload) ? $encodedPayload : '');
        $remoteModified = $this->extract_remote_modified_gmt($remoteCategory);

        if (! $this->is_remote_object_published($remoteCategory, 'publish')) {
            $alreadyUnpublished = is_array($mapping)
                && (string) ($mapping['last_operation_status'] ?? '') === 'unpublished'
                && (string) ($mapping['payload_hash'] ?? '') === $payloadHash
                && (string) ($mapping['remote_modified_gmt'] ?? '') === (string) ($remoteModified ?? '')
                && ($localTermId <= 0 || $this->is_local_term_marked_unpublished($localTermId));

            if ($alreadyUnpublished) {
                return 'skipped';
            }

            if ($localTermId > 0) {
                $this->set_category_unpublished_state($localTermId, true);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::CATEGORY_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localTermId,
                'remote_slug'           => $slug,
                'remote_modified_gmt'   => $remoteModified,
                'payload_hash'          => $payloadHash,
                'last_operation_status' => 'unpublished',
                'last_error_message'    => null,
            ]);

            return $localTermId > 0 ? 'updated' : 'skipped';
        }

        $parentState = $this->resolve_local_parent_state($remoteCategory, $localTerm);
        $parentId = (int) ($parentState['local_parent_id'] ?? 0);
        $needsDeferredParent = ! empty($parentState['needs_deferred_parent']);
        $hasLocalDrift = $localTerm instanceof \WP_Term
            ? $this->has_local_category_drift($localTerm, $remoteCategory, $slug, $parentId, $needsDeferredParent)
            : false;
        $termData = $this->build_category_term_data($remoteCategory, $slug, $parentId);

        $shouldSync = $this->should_sync_object($mapping, $localTerm instanceof \WP_Term, $payloadHash, $remoteModified, $hasLocalDrift);

        if (! $shouldSync) {
            if ($localTermId > 0) {
                $this->set_category_unpublished_state($localTermId, false);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::CATEGORY_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localTermId,
                'remote_slug'           => $slug,
                'remote_modified_gmt'   => $remoteModified,
                'payload_hash'          => $payloadHash,
                'last_operation_status' => 'success',
                'last_error_message'    => null,
            ]);

            return 'skipped';
        }

        if ($localTermId <= 0) {
            $insertResult = wp_insert_term($name, self::CATEGORY_TAXONOMY, $termData);

            if (is_wp_error($insertResult)) {
                throw new \RuntimeException($insertResult->get_error_message());
            }

            $localTermId = (int) ($insertResult['term_id'] ?? 0);

            if ($localTermId <= 0) {
                throw new \RuntimeException('Local category create failed: empty term id.');
            }

            $operation = 'created';
        } else {
            $updateResult = wp_update_term($localTermId, self::CATEGORY_TAXONOMY, array_merge($termData, [
                'name' => $name,
            ]));

            if (is_wp_error($updateResult)) {
                throw new \RuntimeException($updateResult->get_error_message());
            }

            $operation = 'updated';
        }

        update_term_meta($localTermId, self::REMOTE_ID_META_KEY, $remoteId);
        $this->update_category_seo_meta($localTermId, $remoteCategory);
        $this->set_category_unpublished_state($localTermId, false);
        $this->schedule_deferred_parent_assignment(
            $remoteCategory,
            $localTermId,
            $needsDeferredParent,
            $pendingParentAssignments
        );

        $this->mapRepository->upsert([
            'object_type'           => self::CATEGORY_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localTermId,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $remoteModified,
            'payload_hash'          => $payloadHash,
            'last_operation_status' => 'success',
            'last_error_message'    => null,
        ]);

        return $operation;
    }

    private function sync_single_product(
        array $remoteProduct,
        array $settings,
        array $dictionary,
        array &$seenRemoteIds
    ): string {
        $remoteId = (int) ($remoteProduct['id'] ?? 0);

        if ($remoteId <= 0) {
            throw new \RuntimeException('Remote product payload has invalid id.');
        }

        $slug = sanitize_title((string) ($remoteProduct['slug'] ?? ''));
        $title = sanitize_text_field((string) ($remoteProduct['name'] ?? ''));

        if ($slug === '') {
            $slug = sanitize_title($title);
        }

        if ($slug === '') {
            throw new \RuntimeException('Remote product payload has empty slug.');
        }

        $seenRemoteIds[] = $remoteId;
        $seenRemoteIds = array_values(array_unique($seenRemoteIds));

        if ($this->regionalizer->is_excluded_slug($slug, $settings)) {
            return 'skipped';
        }

        $mapping = $this->mapRepository->find_by_remote(self::PRODUCT_OBJECT_TYPE, $remoteId);
        $localPost = $this->resolve_local_product($mapping, $slug);
        $localPostId = $localPost instanceof \WP_Post ? (int) $localPost->ID : 0;

        $payload = $this->normalize_product_payload($remoteProduct);
        $encodedPayload = wp_json_encode($payload);
        $payloadHash = hash('sha256', is_string($encodedPayload) ? $encodedPayload : '');
        $remoteModified = $this->extract_remote_modified_gmt($remoteProduct);
        $mappedCategoryIds = $this->resolve_mapped_product_category_ids($remoteProduct);

        if (! $this->is_remote_object_published($remoteProduct, 'publish')) {
            $alreadyUnpublished = is_array($mapping)
                && (string) ($mapping['last_operation_status'] ?? '') === 'unpublished'
                && (string) ($mapping['payload_hash'] ?? '') === $payloadHash
                && (string) ($mapping['remote_modified_gmt'] ?? '') === (string) ($remoteModified ?? '')
                && ($localPostId <= 0 || $this->is_local_post_marked_unpublished($localPostId, self::PRODUCT_POST_TYPE));

            if ($alreadyUnpublished) {
                return 'skipped';
            }

            if ($localPostId > 0) {
                $this->set_product_unpublished_state($localPostId, true);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::PRODUCT_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localPostId,
                'remote_slug'           => $slug,
                'remote_modified_gmt'   => $remoteModified,
                'payload_hash'          => $payloadHash,
                'last_operation_status' => 'unpublished',
                'last_error_message'    => null,
            ]);

            return $localPostId > 0 ? 'updated' : 'skipped';
        }

        $hasLocalDrift = $localPost instanceof \WP_Post
            ? $this->has_local_product_drift($localPost, $remoteProduct, $mappedCategoryIds)
            : false;

        $shouldSync = $this->should_sync_object($mapping, $localPost instanceof \WP_Post, $payloadHash, $remoteModified, $hasLocalDrift);

        if (! $shouldSync) {
            if ($localPostId > 0) {
                $this->set_product_unpublished_state($localPostId, false);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::PRODUCT_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localPostId,
                'remote_slug'           => $slug,
                'remote_modified_gmt'   => $remoteModified,
                'payload_hash'          => $payloadHash,
                'last_operation_status' => 'success',
                'last_error_message'    => null,
            ]);

            return 'skipped';
        }

        $postData = $this->build_product_post_data($remoteProduct);

        if ($localPostId <= 0) {
            $createData = array_merge($postData, [
                'post_type' => self::PRODUCT_POST_TYPE,
            ]);

            $insertResult = wp_insert_post($createData, true);

            if (is_wp_error($insertResult)) {
                throw new \RuntimeException($insertResult->get_error_message());
            }

            $localPostId = (int) $insertResult;

            if ($localPostId <= 0) {
                throw new \RuntimeException('Local product create failed.');
            }

            $operation = 'created';
        } else {
            $updateData = array_merge($postData, [
                'ID' => $localPostId,
            ]);

            $updateResult = wp_update_post($updateData, true);

            if (is_wp_error($updateResult)) {
                throw new \RuntimeException($updateResult->get_error_message());
            }

            $operation = 'updated';
        }

        update_post_meta($localPostId, self::REMOTE_ID_META_KEY, $remoteId);

        $this->sync_product_metas($localPostId, $remoteProduct);
        $this->sync_product_categories($localPostId, $mappedCategoryIds);
        $this->sync_product_type($localPostId, $remoteProduct);
        $this->sync_product_seo_meta($localPostId, $remoteProduct);
        $this->apply_product_seo_regionalization($localPostId, $dictionary);
        $this->set_product_unpublished_state($localPostId, false);

        $this->mapRepository->upsert([
            'object_type'           => self::PRODUCT_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localPostId,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $remoteModified,
            'payload_hash'          => $payloadHash,
            'last_operation_status' => 'success',
            'last_error_message'    => null,
        ]);

        return $operation;
    }

    private function sync_single_page(array $remotePage, array $settings, array &$seenRemoteIds): string
    {
        $remoteId = (int) ($remotePage['id'] ?? 0);

        if ($remoteId <= 0) {
            throw new \RuntimeException('Remote page payload has invalid id.');
        }

        $slug = sanitize_title((string) ($remotePage['slug'] ?? ''));
        $title = sanitize_text_field($this->extract_remote_page_text($remotePage, 'title'));

        if ($slug === '') {
            $slug = sanitize_title($title);
        }

        if ($slug === '') {
            throw new \RuntimeException('Remote page payload has empty slug.');
        }

        $seenRemoteIds[] = $remoteId;
        $seenRemoteIds = array_values(array_unique($seenRemoteIds));

        if ($this->regionalizer->is_excluded_slug($slug, $settings)) {
            return 'skipped';
        }

        $mapping = $this->mapRepository->find_by_remote(self::PAGE_OBJECT_TYPE, $remoteId);
        $localPost = $this->resolve_local_page($mapping, $slug);
        $localPostId = $localPost instanceof \WP_Post ? (int) $localPost->ID : 0;

        $payload = $this->normalize_page_payload($remotePage);
        $encodedPayload = wp_json_encode($payload);
        $payloadHash = hash('sha256', is_string($encodedPayload) ? $encodedPayload : '');
        $remoteModified = $this->extract_remote_modified_gmt($remotePage);

        if (! $this->is_remote_object_published($remotePage, 'publish')) {
            $alreadyUnpublished = is_array($mapping)
                && (string) ($mapping['last_operation_status'] ?? '') === 'unpublished'
                && (string) ($mapping['payload_hash'] ?? '') === $payloadHash
                && (string) ($mapping['remote_modified_gmt'] ?? '') === (string) ($remoteModified ?? '')
                && ($localPostId <= 0 || $this->is_local_post_marked_unpublished($localPostId, self::PAGE_POST_TYPE));

            if ($alreadyUnpublished) {
                return 'skipped';
            }

            if ($localPostId > 0) {
                $this->set_page_unpublished_state($localPostId, true);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::PAGE_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localPostId,
                'remote_slug'           => $slug,
                'remote_modified_gmt'   => $remoteModified,
                'payload_hash'          => $payloadHash,
                'last_operation_status' => 'unpublished',
                'last_error_message'    => null,
            ]);

            return $localPostId > 0 ? 'updated' : 'skipped';
        }

        $hasLocalDrift = $localPost instanceof \WP_Post ? $this->has_local_page_drift($localPost, $remotePage) : false;
        $shouldSync = $this->should_sync_object($mapping, $localPost instanceof \WP_Post, $payloadHash, $remoteModified, $hasLocalDrift);

        if (! $shouldSync) {
            if ($localPostId > 0) {
                $this->set_page_unpublished_state($localPostId, false);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::PAGE_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localPostId,
                'remote_slug'           => $slug,
                'remote_modified_gmt'   => $remoteModified,
                'payload_hash'          => $payloadHash,
                'last_operation_status' => 'success',
                'last_error_message'    => null,
            ]);

            return 'skipped';
        }

        $postData = $this->build_page_post_data($remotePage);

        if ($localPostId <= 0) {
            $createData = array_merge($postData, [
                'post_type' => self::PAGE_POST_TYPE,
            ]);

            $insertResult = wp_insert_post($createData, true);

            if (is_wp_error($insertResult)) {
                throw new \RuntimeException($insertResult->get_error_message());
            }

            $localPostId = (int) $insertResult;

            if ($localPostId <= 0) {
                throw new \RuntimeException('Local page create failed.');
            }

            $operation = 'created';
        } else {
            $updateData = array_merge($postData, [
                'ID' => $localPostId,
            ]);

            $updateResult = wp_update_post($updateData, true);

            if (is_wp_error($updateResult)) {
                throw new \RuntimeException($updateResult->get_error_message());
            }

            $operation = 'updated';
        }

        update_post_meta($localPostId, self::REMOTE_ID_META_KEY, $remoteId);
        $this->set_page_unpublished_state($localPostId, false);

        $this->mapRepository->upsert([
            'object_type'           => self::PAGE_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localPostId,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $remoteModified,
            'payload_hash'          => $payloadHash,
            'last_operation_status' => 'success',
            'last_error_message'    => null,
        ]);

        return $operation;
    }

    private function resolve_local_term(?array $mapping, string $slug): ?\WP_Term
    {
        if (is_array($mapping) && (int) ($mapping['local_id'] ?? 0) > 0) {
            $term = get_term((int) $mapping['local_id'], self::CATEGORY_TAXONOMY);

            if ($term instanceof \WP_Term) {
                return $term;
            }
        }

        $bySlug = get_term_by('slug', $slug, self::CATEGORY_TAXONOMY);

        return $bySlug instanceof \WP_Term ? $bySlug : null;
    }

    private function resolve_local_product(?array $mapping, string $slug): ?\WP_Post
    {
        if (is_array($mapping) && (int) ($mapping['local_id'] ?? 0) > 0) {
            $post = get_post((int) $mapping['local_id']);

            if ($post instanceof \WP_Post && $post->post_type === self::PRODUCT_POST_TYPE) {
                return $post;
            }
        }

        $post = get_page_by_path($slug, OBJECT, self::PRODUCT_POST_TYPE);

        return $post instanceof \WP_Post ? $post : null;
    }

    private function resolve_local_page(?array $mapping, string $slug): ?\WP_Post
    {
        if (is_array($mapping) && (int) ($mapping['local_id'] ?? 0) > 0) {
            $post = get_post((int) $mapping['local_id']);

            if ($post instanceof \WP_Post && $post->post_type === self::PAGE_POST_TYPE) {
                return $post;
            }
        }

        $post = get_page_by_path($slug, OBJECT, self::PAGE_POST_TYPE);

        return $post instanceof \WP_Post ? $post : null;
    }

    private function resolve_local_parent_state(array $remoteCategory, ?\WP_Term $localTerm): array
    {
        $remoteParentId = (int) ($remoteCategory['parent'] ?? 0);

        if ($remoteParentId <= 0) {
            return [
                'local_parent_id'       => 0,
                'needs_deferred_parent' => false,
            ];
        }

        $parentMap = $this->mapRepository->find_by_remote(self::CATEGORY_OBJECT_TYPE, $remoteParentId);
        $localParentId = (int) ($parentMap['local_id'] ?? 0);

        if ($localParentId <= 0) {
            if ($localTerm instanceof \WP_Term) {
                return [
                    'local_parent_id'       => (int) $localTerm->parent,
                    'needs_deferred_parent' => true,
                ];
            }

            return [
                'local_parent_id'       => 0,
                'needs_deferred_parent' => true,
            ];
        }

        $parentTerm = get_term($localParentId, self::CATEGORY_TAXONOMY);

        if (! $parentTerm instanceof \WP_Term) {
            return [
                'local_parent_id'       => 0,
                'needs_deferred_parent' => true,
            ];
        }

        return [
            'local_parent_id'       => $localParentId,
            'needs_deferred_parent' => false,
        ];
    }

    private function normalize_category_payload(array $remoteCategory): array
    {
        return [
            'id'                   => (int) ($remoteCategory['id'] ?? 0),
            'name'                 => sanitize_text_field((string) ($remoteCategory['name'] ?? '')),
            'slug'                 => sanitize_title((string) ($remoteCategory['slug'] ?? '')),
            'description'          => wp_kses_post((string) ($remoteCategory['description'] ?? '')),
            'parent'               => (int) ($remoteCategory['parent'] ?? 0),
            'seo_meta_title'       => $this->extract_remote_seo_value($remoteCategory, 'seo_meta_title'),
            'seo_h1'               => $this->extract_remote_seo_value($remoteCategory, 'seo_h1'),
            'seo_meta_description' => $this->extract_remote_seo_value($remoteCategory, 'seo_meta_description'),
            'modified_gmt'         => $this->extract_remote_modified_gmt($remoteCategory),
        ];
    }

    private function normalize_product_payload(array $remoteProduct): array
    {
        $categoryIds = array_map('intval', wp_list_pluck((array) ($remoteProduct['categories'] ?? []), 'id'));
        $categoryIds = array_values(array_unique($categoryIds));
        sort($categoryIds);

        return [
            'id'                   => (int) ($remoteProduct['id'] ?? 0),
            'name'                 => sanitize_text_field((string) ($remoteProduct['name'] ?? '')),
            'slug'                 => sanitize_title((string) ($remoteProduct['slug'] ?? '')),
            'status'               => $this->normalize_post_status((string) ($remoteProduct['status'] ?? 'publish')),
            'description'          => wp_kses_post((string) ($remoteProduct['description'] ?? '')),
            'short_description'    => wp_kses_post((string) ($remoteProduct['short_description'] ?? '')),
            'sku'                  => sanitize_text_field((string) ($remoteProduct['sku'] ?? '')),
            'regular_price'        => $this->sanitize_decimal_string((string) ($remoteProduct['regular_price'] ?? '')),
            'sale_price'           => $this->sanitize_decimal_string((string) ($remoteProduct['sale_price'] ?? '')),
            'price'                => $this->sanitize_decimal_string((string) ($remoteProduct['price'] ?? '')),
            'weight'               => $this->sanitize_decimal_string((string) ($remoteProduct['weight'] ?? '')),
            'length'               => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['length'] ?? ''))),
            'width'                => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['width'] ?? ''))),
            'height'               => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['height'] ?? ''))),
            'virtual'              => $this->sanitize_bool_to_flag($remoteProduct['virtual'] ?? false),
            'downloadable'         => $this->sanitize_bool_to_flag($remoteProduct['downloadable'] ?? false),
            'sold_individually'    => $this->sanitize_bool_to_flag($remoteProduct['sold_individually'] ?? false),
            'tax_status'           => sanitize_key((string) ($remoteProduct['tax_status'] ?? 'taxable')),
            'tax_class'            => sanitize_text_field((string) ($remoteProduct['tax_class'] ?? '')),
            'catalog_visibility'   => sanitize_key((string) ($remoteProduct['catalog_visibility'] ?? 'visible')),
            'menu_order'           => (int) ($remoteProduct['menu_order'] ?? 0),
            'backorders'           => sanitize_key((string) ($remoteProduct['backorders'] ?? 'no')),
            'low_stock_amount'     => sanitize_text_field((string) ($remoteProduct['low_stock_amount'] ?? '')),
            'purchase_note'        => sanitize_textarea_field((string) ($remoteProduct['purchase_note'] ?? '')),
            'button_text'          => sanitize_text_field((string) ($remoteProduct['button_text'] ?? '')),
            'type'                 => sanitize_key((string) ($remoteProduct['type'] ?? 'simple')),
            'min_quantity'         => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'min_quantity')),
            'max_quantity'         => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'max_quantity')),
            'product_step'         => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'product_step')),
            'categories'           => $categoryIds,
            'images'               => $this->normalize_for_hash((array) ($remoteProduct['images'] ?? [])),
            'attributes'           => $this->normalize_for_hash((array) ($remoteProduct['attributes'] ?? [])),
            'tags'                 => $this->normalize_for_hash((array) ($remoteProduct['tags'] ?? [])),
            'downloads'            => $this->normalize_for_hash((array) ($remoteProduct['downloads'] ?? [])),
            'seo_meta_title'       => $this->extract_remote_seo_value($remoteProduct, 'seo_meta_title'),
            'seo_meta_description' => $this->extract_remote_seo_value($remoteProduct, 'seo_meta_description'),
            'modified_gmt'         => $this->extract_remote_modified_gmt($remoteProduct),
        ];
    }

    private function normalize_page_payload(array $remotePage): array
    {
        return [
            'id'           => (int) ($remotePage['id'] ?? 0),
            'title'        => sanitize_text_field($this->extract_remote_page_text($remotePage, 'title')),
            'slug'         => sanitize_title((string) ($remotePage['slug'] ?? '')),
            'status'       => $this->normalize_post_status((string) ($remotePage['status'] ?? 'publish')),
            'content'      => wp_kses_post($this->extract_remote_page_text($remotePage, 'content')),
            'excerpt'      => wp_kses_post($this->extract_remote_page_text($remotePage, 'excerpt')),
            'modified_gmt' => $this->extract_remote_modified_gmt($remotePage),
        ];
    }

    private function should_sync_object(
        ?array $mapping,
        bool $localExists,
        string $payloadHash,
        ?string $remoteModified,
        bool $hasLocalDrift
    ): bool {
        if (! is_array($mapping)) {
            return true;
        }

        if (! $localExists) {
            return true;
        }

        $lastStatus = (string) ($mapping['last_operation_status'] ?? '');

        if ($lastStatus !== 'success') {
            return true;
        }

        $storedPayloadHash = (string) ($mapping['payload_hash'] ?? '');

        if ($storedPayloadHash !== $payloadHash) {
            return true;
        }

        if ($storedPayloadHash === '' && (string) ($mapping['remote_modified_gmt'] ?? '') !== (string) ($remoteModified ?? '')) {
            return true;
        }

        if ($hasLocalDrift) {
            return true;
        }

        return false;
    }

    private function build_category_term_data(array $remoteCategory, string $slug, int $parentId): array
    {
        return [
            'slug'        => $slug,
            'description' => wp_kses_post((string) ($remoteCategory['description'] ?? '')),
            'parent'      => $parentId,
        ];
    }

    private function build_product_post_data(array $remoteProduct): array
    {
        $status = $this->normalize_post_status((string) ($remoteProduct['status'] ?? 'publish'));

        return [
            'post_title'   => sanitize_text_field((string) ($remoteProduct['name'] ?? '')),
            'post_name'    => sanitize_title((string) ($remoteProduct['slug'] ?? '')),
            'post_status'  => $status,
            'post_content' => wp_kses_post((string) ($remoteProduct['description'] ?? '')),
            'post_excerpt' => wp_kses_post((string) ($remoteProduct['short_description'] ?? '')),
            'menu_order'   => (int) ($remoteProduct['menu_order'] ?? 0),
        ];
    }

    private function build_page_post_data(array $remotePage): array
    {
        $status = $this->normalize_post_status((string) ($remotePage['status'] ?? 'publish'));

        return [
            'post_title'   => sanitize_text_field($this->extract_remote_page_text($remotePage, 'title')),
            'post_name'    => sanitize_title((string) ($remotePage['slug'] ?? '')),
            'post_status'  => $status,
            'post_content' => wp_kses_post($this->extract_remote_page_text($remotePage, 'content')),
            'post_excerpt' => wp_kses_post($this->extract_remote_page_text($remotePage, 'excerpt')),
        ];
    }

    private function has_local_category_drift(
        \WP_Term $localTerm,
        array $remoteCategory,
        string $expectedSlug,
        int $expectedParentId,
        bool $skipParentCheck
    ): bool {
        $expectedName = sanitize_text_field((string) ($remoteCategory['name'] ?? ''));
        $expectedDescription = trim((string) wp_kses_post((string) ($remoteCategory['description'] ?? '')));
        $localDescription = trim((string) wp_kses_post((string) $localTerm->description));

        if ($localTerm->name !== $expectedName) {
            return true;
        }

        if ($localTerm->slug !== $expectedSlug) {
            return true;
        }

        if ($localDescription !== $expectedDescription) {
            return true;
        }

        if (! $skipParentCheck && (int) $localTerm->parent !== $expectedParentId) {
            return true;
        }

        $fields = [
            'seo_meta_title',
            'seo_h1',
            'seo_meta_description',
        ];

        foreach ($fields as $field) {
            $expectedMeta = $this->extract_remote_seo_value($remoteCategory, $field);
            $localMeta = (string) get_term_meta((int) $localTerm->term_id, $field, true);

            if ($field === 'seo_meta_description') {
                $expectedMeta = sanitize_textarea_field($expectedMeta);
                $localMeta = sanitize_textarea_field($localMeta);
            } else {
                $expectedMeta = sanitize_text_field($expectedMeta);
                $localMeta = sanitize_text_field($localMeta);
            }

            if ($localMeta !== $expectedMeta) {
                return true;
            }
        }

        $unpublishedFlag = (string) get_term_meta((int) $localTerm->term_id, self::UNPUBLISHED_META_KEY, true);

        return $unpublishedFlag !== '';
    }

    private function has_local_product_drift(
        \WP_Post $localPost,
        array $remoteProduct,
        array $mappedCategoryIds
    ): bool {
        $expectedStatus = $this->normalize_post_status((string) ($remoteProduct['status'] ?? 'publish'));

        if ($localPost->post_title !== sanitize_text_field((string) ($remoteProduct['name'] ?? ''))) {
            return true;
        }

        if ($localPost->post_name !== sanitize_title((string) ($remoteProduct['slug'] ?? ''))) {
            return true;
        }

        if ($localPost->post_status !== $expectedStatus) {
            return true;
        }

        $currentCategoryIds = wp_get_post_terms((int) $localPost->ID, self::CATEGORY_TAXONOMY, ['fields' => 'ids']);
        $currentCategoryIds = array_map('intval', is_array($currentCategoryIds) ? $currentCategoryIds : []);
        sort($currentCategoryIds);

        $expectedCategoryIds = array_map('intval', $mappedCategoryIds);
        sort($expectedCategoryIds);

        if ($currentCategoryIds !== $expectedCategoryIds) {
            return true;
        }

        $metaChecks = [
            '_sku'           => sanitize_text_field((string) ($remoteProduct['sku'] ?? '')),
            '_regular_price' => $this->sanitize_decimal_string((string) ($remoteProduct['regular_price'] ?? '')),
            '_sale_price'    => $this->sanitize_decimal_string((string) ($remoteProduct['sale_price'] ?? '')),
            '_weight'        => $this->sanitize_decimal_string((string) ($remoteProduct['weight'] ?? '')),
            '_length'        => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['length'] ?? ''))),
            '_width'         => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['width'] ?? ''))),
            '_height'        => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['height'] ?? ''))),
            'min_quantity'   => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'min_quantity')),
            'max_quantity'   => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'max_quantity')),
            'product_step'   => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'product_step')),
        ];

        foreach ($metaChecks as $metaKey => $expectedValue) {
            $localValue = (string) get_post_meta((int) $localPost->ID, $metaKey, true);

            if ($localValue !== $expectedValue) {
                return true;
            }
        }

        $unpublishedFlag = (string) get_post_meta((int) $localPost->ID, self::UNPUBLISHED_META_KEY, true);

        return $unpublishedFlag !== '';
    }

    private function has_local_page_drift(\WP_Post $localPost, array $remotePage): bool
    {
        $expectedStatus = $this->normalize_post_status((string) ($remotePage['status'] ?? 'publish'));
        $expectedTitle = sanitize_text_field($this->extract_remote_page_text($remotePage, 'title'));
        $expectedSlug = sanitize_title((string) ($remotePage['slug'] ?? ''));
        $expectedContent = wp_kses_post($this->extract_remote_page_text($remotePage, 'content'));
        $expectedExcerpt = wp_kses_post($this->extract_remote_page_text($remotePage, 'excerpt'));

        if ($localPost->post_title !== $expectedTitle) {
            return true;
        }

        if ($localPost->post_name !== $expectedSlug) {
            return true;
        }

        if ($localPost->post_status !== $expectedStatus) {
            return true;
        }

        if ((string) wp_kses_post((string) $localPost->post_content) !== (string) $expectedContent) {
            return true;
        }

        if ((string) wp_kses_post((string) $localPost->post_excerpt) !== (string) $expectedExcerpt) {
            return true;
        }

        $unpublishedFlag = (string) get_post_meta((int) $localPost->ID, self::UNPUBLISHED_META_KEY, true);

        return $unpublishedFlag !== '';
    }

    private function update_category_seo_meta(int $termId, array $remoteCategory): void
    {
        $fields = [
            'seo_meta_title',
            'seo_h1',
            'seo_meta_description',
        ];

        foreach ($fields as $field) {
            $value = $this->extract_remote_seo_value($remoteCategory, $field);
            update_term_meta($termId, $field, $value);
        }
    }

    private function sync_product_seo_meta(int $postId, array $remoteProduct): void
    {
        update_post_meta($postId, 'seo_meta_title', $this->extract_remote_seo_value($remoteProduct, 'seo_meta_title'));
        update_post_meta($postId, 'seo_meta_description', $this->extract_remote_seo_value($remoteProduct, 'seo_meta_description'));
    }

    private function apply_product_seo_regionalization(int $postId, array $dictionary): void
    {
        if ($dictionary === []) {
            return;
        }

        $fields = ['seo_meta_title', 'seo_meta_description'];

        foreach ($fields as $field) {
            $current = (string) get_post_meta($postId, $field, true);

            if ($current === '') {
                continue;
            }

            $regionalized = $this->regionalizer->regionalize_with_dictionary($current, $dictionary);

            if ($regionalized !== $current) {
                update_post_meta($postId, $field, $regionalized);
            }
        }
    }

    private function sync_product_metas(int $postId, array $remoteProduct): void
    {
        $metaMap = [
            '_sku'               => sanitize_text_field((string) ($remoteProduct['sku'] ?? '')),
            '_regular_price'     => $this->sanitize_decimal_string((string) ($remoteProduct['regular_price'] ?? '')),
            '_sale_price'        => $this->sanitize_decimal_string((string) ($remoteProduct['sale_price'] ?? '')),
            '_price'             => $this->resolve_effective_price($remoteProduct),
            '_weight'            => $this->sanitize_decimal_string((string) ($remoteProduct['weight'] ?? '')),
            '_length'            => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['length'] ?? ''))),
            '_width'             => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['width'] ?? ''))),
            '_height'            => $this->sanitize_decimal_string((string) (($remoteProduct['dimensions']['height'] ?? ''))),
            '_virtual'           => $this->sanitize_bool_to_yes_no($remoteProduct['virtual'] ?? false),
            '_downloadable'      => $this->sanitize_bool_to_yes_no($remoteProduct['downloadable'] ?? false),
            '_sold_individually' => $this->sanitize_bool_to_yes_no($remoteProduct['sold_individually'] ?? false),
            '_tax_status'        => sanitize_key((string) ($remoteProduct['tax_status'] ?? 'taxable')),
            '_tax_class'         => sanitize_text_field((string) ($remoteProduct['tax_class'] ?? '')),
            '_catalog_visibility'=> sanitize_key((string) ($remoteProduct['catalog_visibility'] ?? 'visible')),
            '_backorders'        => sanitize_key((string) ($remoteProduct['backorders'] ?? 'no')),
            '_low_stock_amount'  => sanitize_text_field((string) ($remoteProduct['low_stock_amount'] ?? '')),
            '_purchase_note'     => sanitize_textarea_field((string) ($remoteProduct['purchase_note'] ?? '')),
            '_button_text'       => sanitize_text_field((string) ($remoteProduct['button_text'] ?? '')),
            'min_quantity'       => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'min_quantity')),
            'max_quantity'       => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'max_quantity')),
            'product_step'       => $this->sanitize_quantity_rule($this->extract_remote_meta_data_value($remoteProduct, 'product_step')),
            '_crs_remote_images_json'      => wp_json_encode((array) ($remoteProduct['images'] ?? [])),
            '_crs_remote_attributes_json'  => wp_json_encode((array) ($remoteProduct['attributes'] ?? [])),
            '_crs_remote_tags_json'        => wp_json_encode((array) ($remoteProduct['tags'] ?? [])),
            '_crs_remote_downloads_json'   => wp_json_encode((array) ($remoteProduct['downloads'] ?? [])),
            '_crs_remote_meta_data_json'   => wp_json_encode((array) ($remoteProduct['meta_data'] ?? [])),
            '_crs_remote_catalog_payload'  => wp_json_encode($this->normalize_product_payload($remoteProduct)),
        ];

        foreach ($metaMap as $key => $value) {
            $safeValue = is_string($value) ? $value : '';
            update_post_meta($postId, $key, $safeValue);
        }
    }

    private function sync_product_categories(int $postId, array $localCategoryIds): void
    {
        $terms = array_values(array_unique(array_map('intval', $localCategoryIds)));
        wp_set_object_terms($postId, $terms, self::CATEGORY_TAXONOMY, false);
    }

    private function sync_product_type(int $postId, array $remoteProduct): void
    {
        $productType = sanitize_key((string) ($remoteProduct['type'] ?? 'simple'));

        if ($productType === '') {
            $productType = 'simple';
        }

        wp_set_object_terms($postId, [$productType], 'product_type', false);
    }

    private function resolve_mapped_product_category_ids(array $remoteProduct): array
    {
        $remoteCategories = (array) ($remoteProduct['categories'] ?? []);
        $localIds = [];

        foreach ($remoteCategories as $category) {
            if (! is_array($category)) {
                continue;
            }

            $remoteCategoryId = (int) ($category['id'] ?? 0);

            if ($remoteCategoryId <= 0) {
                continue;
            }

            $mapping = $this->mapRepository->find_by_remote(self::CATEGORY_OBJECT_TYPE, $remoteCategoryId);
            $localId = (int) ($mapping['local_id'] ?? 0);

            if ($localId <= 0) {
                continue;
            }

            $term = get_term($localId, self::CATEGORY_TAXONOMY);

            if (! $term instanceof \WP_Term) {
                continue;
            }

            $localIds[] = $localId;
        }

        return array_values(array_unique($localIds));
    }

    private function apply_category_unpublish_logic(array $seenRemoteIds): int
    {
        $unpublishedCount = 0;
        $mappings = $this->mapRepository->list_by_object_type(self::CATEGORY_OBJECT_TYPE);
        $seenMap = array_fill_keys(array_map('intval', $seenRemoteIds), true);

        foreach ($mappings as $mapping) {
            $remoteId = (int) ($mapping['remote_id'] ?? 0);

            if ($remoteId <= 0 || isset($seenMap[$remoteId])) {
                continue;
            }

            $localId = (int) ($mapping['local_id'] ?? 0);
            $alreadyUnpublished = (string) ($mapping['last_operation_status'] ?? '') === 'unpublished'
                && ($localId <= 0 || $this->is_local_term_marked_unpublished($localId));

            if ($alreadyUnpublished) {
                continue;
            }

            if ($localId > 0) {
                $this->set_category_unpublished_state($localId, true);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::CATEGORY_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localId,
                'remote_slug'           => (string) ($mapping['remote_slug'] ?? ''),
                'remote_modified_gmt'   => isset($mapping['remote_modified_gmt']) && $mapping['remote_modified_gmt'] !== ''
                    ? (string) $mapping['remote_modified_gmt']
                    : null,
                'payload_hash'          => (string) ($mapping['payload_hash'] ?? ''),
                'last_operation_status' => 'unpublished',
                'last_error_message'    => null,
            ]);

            $unpublishedCount++;
        }

        return $unpublishedCount;
    }

    private function apply_product_unpublish_logic(array $seenRemoteIds): int
    {
        $unpublishedCount = 0;
        $mappings = $this->mapRepository->list_by_object_type(self::PRODUCT_OBJECT_TYPE);
        $seenMap = array_fill_keys(array_map('intval', $seenRemoteIds), true);

        foreach ($mappings as $mapping) {
            $remoteId = (int) ($mapping['remote_id'] ?? 0);

            if ($remoteId <= 0 || isset($seenMap[$remoteId])) {
                continue;
            }

            $localId = (int) ($mapping['local_id'] ?? 0);
            $alreadyUnpublished = (string) ($mapping['last_operation_status'] ?? '') === 'unpublished'
                && ($localId <= 0 || $this->is_local_post_marked_unpublished($localId, self::PRODUCT_POST_TYPE));

            if ($alreadyUnpublished) {
                continue;
            }

            if ($localId > 0) {
                $this->set_product_unpublished_state($localId, true);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::PRODUCT_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localId,
                'remote_slug'           => (string) ($mapping['remote_slug'] ?? ''),
                'remote_modified_gmt'   => isset($mapping['remote_modified_gmt']) && $mapping['remote_modified_gmt'] !== ''
                    ? (string) $mapping['remote_modified_gmt']
                    : null,
                'payload_hash'          => (string) ($mapping['payload_hash'] ?? ''),
                'last_operation_status' => 'unpublished',
                'last_error_message'    => null,
            ]);

            $unpublishedCount++;
        }

        return $unpublishedCount;
    }

    private function apply_page_unpublish_logic(array $seenRemoteIds): int
    {
        $unpublishedCount = 0;
        $mappings = $this->mapRepository->list_by_object_type(self::PAGE_OBJECT_TYPE);
        $seenMap = array_fill_keys(array_map('intval', $seenRemoteIds), true);

        foreach ($mappings as $mapping) {
            $remoteId = (int) ($mapping['remote_id'] ?? 0);

            if ($remoteId <= 0 || isset($seenMap[$remoteId])) {
                continue;
            }

            $localId = (int) ($mapping['local_id'] ?? 0);
            $alreadyUnpublished = (string) ($mapping['last_operation_status'] ?? '') === 'unpublished'
                && ($localId <= 0 || $this->is_local_post_marked_unpublished($localId, self::PAGE_POST_TYPE));

            if ($alreadyUnpublished) {
                continue;
            }

            if ($localId > 0) {
                $this->set_page_unpublished_state($localId, true);
            }

            $this->mapRepository->upsert([
                'object_type'           => self::PAGE_OBJECT_TYPE,
                'remote_id'             => $remoteId,
                'local_id'              => $localId,
                'remote_slug'           => (string) ($mapping['remote_slug'] ?? ''),
                'remote_modified_gmt'   => isset($mapping['remote_modified_gmt']) && $mapping['remote_modified_gmt'] !== ''
                    ? (string) $mapping['remote_modified_gmt']
                    : null,
                'payload_hash'          => (string) ($mapping['payload_hash'] ?? ''),
                'last_operation_status' => 'unpublished',
                'last_error_message'    => null,
            ]);

            $unpublishedCount++;
        }

        return $unpublishedCount;
    }

    private function set_category_unpublished_state(int $termId, bool $isUnpublished): void
    {
        if ($termId <= 0) {
            return;
        }

        if ($isUnpublished) {
            update_term_meta($termId, self::UNPUBLISHED_META_KEY, '1');
            return;
        }

        delete_term_meta($termId, self::UNPUBLISHED_META_KEY);
    }

    private function set_product_unpublished_state(int $postId, bool $isUnpublished): void
    {
        if ($postId <= 0) {
            return;
        }

        if ($isUnpublished) {
            wp_update_post([
                'ID'          => $postId,
                'post_status' => 'draft',
            ]);

            update_post_meta($postId, self::UNPUBLISHED_META_KEY, '1');
            return;
        }

        delete_post_meta($postId, self::UNPUBLISHED_META_KEY);
    }

    private function set_page_unpublished_state(int $postId, bool $isUnpublished): void
    {
        if ($postId <= 0) {
            return;
        }

        if ($isUnpublished) {
            wp_update_post([
                'ID'          => $postId,
                'post_status' => 'draft',
            ]);

            update_post_meta($postId, self::UNPUBLISHED_META_KEY, '1');
            return;
        }

        delete_post_meta($postId, self::UNPUBLISHED_META_KEY);
    }

    private function is_local_term_marked_unpublished(int $termId): bool
    {
        if ($termId <= 0) {
            return false;
        }

        return (string) get_term_meta($termId, self::UNPUBLISHED_META_KEY, true) === '1';
    }

    private function is_local_post_marked_unpublished(int $postId, string $postType): bool
    {
        if ($postId <= 0) {
            return false;
        }

        $post = get_post($postId);

        if (! $post instanceof \WP_Post || $post->post_type !== $postType) {
            return false;
        }

        $flag = (string) get_post_meta($postId, self::UNPUBLISHED_META_KEY, true);

        return $post->post_status === 'draft' && $flag === '1';
    }

    private function schedule_deferred_parent_assignment(
        array $remoteCategory,
        int $localTermId,
        bool $needsDeferredParent,
        array &$pendingParentAssignments
    ): void {
        if (! $needsDeferredParent || $localTermId <= 0) {
            return;
        }

        $remoteParentId = (int) ($remoteCategory['parent'] ?? 0);

        if ($remoteParentId <= 0) {
            return;
        }

        $pendingParentAssignments[] = [
            'local_term_id'    => $localTermId,
            'remote_parent_id' => $remoteParentId,
        ];
    }

    private function apply_deferred_parent_assignments(array $pendingParentAssignments): array
    {
        $updated = 0;
        $errors = 0;

        if ($pendingParentAssignments === []) {
            return [
                'updated' => 0,
                'errors'  => 0,
            ];
        }

        $uniqueAssignments = [];

        foreach ($pendingParentAssignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $localTermId = (int) ($assignment['local_term_id'] ?? 0);
            $remoteParentId = (int) ($assignment['remote_parent_id'] ?? 0);

            if ($localTermId <= 0 || $remoteParentId <= 0) {
                continue;
            }

            $uniqueAssignments[$localTermId] = $remoteParentId;
        }

        foreach ($uniqueAssignments as $localTermId => $remoteParentId) {
            $parentMap = $this->mapRepository->find_by_remote(self::CATEGORY_OBJECT_TYPE, (int) $remoteParentId);
            $localParentId = (int) ($parentMap['local_id'] ?? 0);

            if ($localParentId <= 0) {
                $errors++;
                continue;
            }

            $childTerm = get_term((int) $localTermId, self::CATEGORY_TAXONOMY);
            $parentTerm = get_term($localParentId, self::CATEGORY_TAXONOMY);

            if (! $childTerm instanceof \WP_Term || ! $parentTerm instanceof \WP_Term) {
                $errors++;
                continue;
            }

            if ((int) $childTerm->parent === $localParentId) {
                continue;
            }

            $updateResult = wp_update_term((int) $localTermId, self::CATEGORY_TAXONOMY, [
                'parent' => $localParentId,
            ]);

            if (is_wp_error($updateResult)) {
                $errors++;
                continue;
            }

            $updated++;
        }

        return [
            'updated' => $updated,
            'errors'  => $errors,
        ];
    }

    private function is_remote_object_published(array $remoteObject, string $publishedStatus): bool
    {
        if (! array_key_exists('status', $remoteObject)) {
            return true;
        }

        $status = sanitize_key((string) $remoteObject['status']);

        if ($status === '') {
            return true;
        }

        return $status === $publishedStatus;
    }

    private function normalize_post_status(string $status): string
    {
        $normalized = sanitize_key($status);

        if (in_array($normalized, ['publish', 'draft', 'pending', 'private'], true)) {
            return $normalized;
        }

        return 'draft';
    }

    private function extract_remote_seo_value(array $remoteObject, string $key): string
    {
        if (array_key_exists($key, $remoteObject)) {
            return $this->sanitize_seo_value($key, $remoteObject[$key]);
        }

        $metaData = $remoteObject['meta_data'] ?? [];

        if (! is_array($metaData)) {
            return '';
        }

        foreach ($metaData as $metaItem) {
            if (! is_array($metaItem)) {
                continue;
            }

            if ((string) ($metaItem['key'] ?? '') !== $key) {
                continue;
            }

            return $this->sanitize_seo_value($key, $metaItem['value'] ?? '');
        }

        return '';
    }

    private function sanitize_seo_value(string $key, $value): string
    {
        $raw = is_scalar($value) ? (string) $value : '';

        if ($key === 'seo_meta_description') {
            return sanitize_textarea_field($raw);
        }

        return sanitize_text_field($raw);
    }

    private function extract_remote_page_text(array $remotePage, string $field): string
    {
        if (! array_key_exists($field, $remotePage)) {
            return '';
        }

        $value = $remotePage[$field];

        if (is_array($value)) {
            if (array_key_exists('raw', $value) && is_scalar($value['raw'])) {
                return (string) $value['raw'];
            }

            $rendered = $value['rendered'] ?? '';
            return is_scalar($rendered) ? (string) $rendered : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function extract_remote_modified_gmt(array $remoteObject): ?string
    {
        $value = $remoteObject['date_modified_gmt'] ?? $remoteObject['modified_gmt'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if (! $timestamp) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function sanitize_decimal_string(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            return '';
        }

        return (string) (float) $value;
    }

    private function sanitize_stock_quantity($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! is_numeric($value)) {
            return '';
        }

        return (string) (int) $value;
    }

    private function sanitize_quantity_rule($value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $stringValue = trim((string) $value);

        if ($stringValue === '') {
            return '';
        }

        return preg_match('/^-?[0-9]+$/', $stringValue) ? $stringValue : '';
    }

    private function extract_remote_meta_data_value(array $remoteObject, string $key)
    {
        $metaData = $remoteObject['meta_data'] ?? [];

        if (! is_array($metaData)) {
            return null;
        }

        foreach ($metaData as $metaItem) {
            if (! is_array($metaItem)) {
                continue;
            }

            if ((string) ($metaItem['key'] ?? '') !== $key) {
                continue;
            }

            return $metaItem['value'] ?? null;
        }

        return null;
    }

    private function sanitize_bool_to_yes_no($value): string
    {
        return $this->sanitize_bool_to_flag($value) === '1' ? 'yes' : 'no';
    }

    private function normalize_for_hash($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->is_assoc_array($value)) {
            ksort($value);

            foreach ($value as $key => $nestedValue) {
                $value[$key] = $this->normalize_for_hash($nestedValue);
            }

            return $value;
        }

        $normalized = [];

        foreach ($value as $nestedValue) {
            $normalized[] = $this->normalize_for_hash($nestedValue);
        }

        usort($normalized, static function ($left, $right): int {
            $leftEncoded = wp_json_encode($left);
            $rightEncoded = wp_json_encode($right);

            return strcmp((string) $leftEncoded, (string) $rightEncoded);
        });

        return array_values($normalized);
    }

    private function is_assoc_array(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function sanitize_bool_to_flag($value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    private function resolve_effective_price(array $remoteProduct): string
    {
        $price = $this->sanitize_decimal_string((string) ($remoteProduct['price'] ?? ''));

        if ($price !== '') {
            return $price;
        }

        $sale = $this->sanitize_decimal_string((string) ($remoteProduct['sale_price'] ?? ''));

        if ($sale !== '') {
            return $sale;
        }

        return $this->sanitize_decimal_string((string) ($remoteProduct['regular_price'] ?? ''));
    }

    private function mark_category_sync_error(array $remoteCategory, string $message): void
    {
        $remoteId = (int) ($remoteCategory['id'] ?? 0);

        if ($remoteId <= 0) {
            return;
        }

        $slug = sanitize_title((string) ($remoteCategory['slug'] ?? ''));
        $mapping = $this->mapRepository->find_by_remote(self::CATEGORY_OBJECT_TYPE, $remoteId);
        $localTerm = $this->resolve_local_term($mapping, $slug);

        $this->mapRepository->upsert([
            'object_type'           => self::CATEGORY_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localTerm instanceof \WP_Term ? (int) $localTerm->term_id : 0,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $this->extract_remote_modified_gmt($remoteCategory),
            'payload_hash'          => '',
            'last_operation_status' => 'error',
            'last_error_message'    => sanitize_text_field($message),
        ]);
    }

    private function mark_product_sync_error(array $remoteProduct, string $message): void
    {
        $remoteId = (int) ($remoteProduct['id'] ?? 0);

        if ($remoteId <= 0) {
            return;
        }

        $slug = sanitize_title((string) ($remoteProduct['slug'] ?? ''));
        $mapping = $this->mapRepository->find_by_remote(self::PRODUCT_OBJECT_TYPE, $remoteId);
        $localPost = $this->resolve_local_product($mapping, $slug);

        $this->mapRepository->upsert([
            'object_type'           => self::PRODUCT_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localPost instanceof \WP_Post ? (int) $localPost->ID : 0,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $this->extract_remote_modified_gmt($remoteProduct),
            'payload_hash'          => '',
            'last_operation_status' => 'error',
            'last_error_message'    => sanitize_text_field($message),
        ]);
    }

    private function mark_page_sync_error(array $remotePage, string $message): void
    {
        $remoteId = (int) ($remotePage['id'] ?? 0);

        if ($remoteId <= 0) {
            return;
        }

        $slug = sanitize_title((string) ($remotePage['slug'] ?? ''));
        $mapping = $this->mapRepository->find_by_remote(self::PAGE_OBJECT_TYPE, $remoteId);
        $localPost = $this->resolve_local_page($mapping, $slug);

        $this->mapRepository->upsert([
            'object_type'           => self::PAGE_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localPost instanceof \WP_Post ? (int) $localPost->ID : 0,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $this->extract_remote_modified_gmt($remotePage),
            'payload_hash'          => '',
            'last_operation_status' => 'error',
            'last_error_message'    => sanitize_text_field($message),
        ]);
    }
}
