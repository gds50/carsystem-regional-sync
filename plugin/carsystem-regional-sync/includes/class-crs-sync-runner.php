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
            $summary = $this->run_categories_sync(Settings::get());

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
                $this->mark_sync_error($remoteCategory, $e->getMessage());
            }
        }

        $parentSyncResult = $this->apply_deferred_parent_assignments($pendingParentAssignments);
        $summary['updated_count'] += (int) ($parentSyncResult['updated'] ?? 0);
        $summary['error_count'] += (int) ($parentSyncResult['errors'] ?? 0);

        $summary['updated_count'] += $this->apply_unpublish_logic($seenRemoteIds);

        if ($summary['error_count'] > 0) {
            $summary['status'] = 'partial';
            $summary['message'] = 'Categories sync finished with partial errors.';
        } else {
            $summary['message'] = 'Categories sync completed successfully.';
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

    private function sync_single_category(
        array $remoteCategory,
        array $settings,
        array &$seenRemoteIds,
        array &$pendingParentAssignments
    ): string
    {
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

        if (! $this->is_remote_category_published($remoteCategory)) {
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

        $shouldSync = $this->should_sync_category(
            $mapping,
            $localTerm,
            $payloadHash,
            $remoteModified,
            $hasLocalDrift
        );

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

    private function should_sync_category(
        ?array $mapping,
        ?\WP_Term $localTerm,
        string $payloadHash,
        ?string $remoteModified,
        bool $hasLocalDrift
    ): bool
    {
        if (! is_array($mapping)) {
            return true;
        }

        if (! $localTerm instanceof \WP_Term) {
            return true;
        }

        $lastStatus = (string) ($mapping['last_operation_status'] ?? '');

        if ($lastStatus !== 'success') {
            return true;
        }

        if ((string) ($mapping['payload_hash'] ?? '') !== $payloadHash) {
            return true;
        }

        if ((string) ($mapping['remote_modified_gmt'] ?? '') !== (string) ($remoteModified ?? '')) {
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

    private function extract_remote_seo_value(array $remoteCategory, string $key): string
    {
        if (array_key_exists($key, $remoteCategory)) {
            return $this->sanitize_seo_value($key, $remoteCategory[$key]);
        }

        $metaData = $remoteCategory['meta_data'] ?? [];

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

    private function extract_remote_modified_gmt(array $remoteCategory): ?string
    {
        $value = $remoteCategory['date_modified_gmt'] ?? $remoteCategory['modified_gmt'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if (! $timestamp) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function apply_unpublish_logic(array $seenRemoteIds): int
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

    private function is_remote_category_published(array $remoteCategory): bool
    {
        if (! array_key_exists('status', $remoteCategory)) {
            return true;
        }

        $status = sanitize_key((string) $remoteCategory['status']);

        if ($status === '') {
            return true;
        }

        return $status === 'publish';
    }

    private function mark_sync_error(array $remoteCategory, string $message): void
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
}
