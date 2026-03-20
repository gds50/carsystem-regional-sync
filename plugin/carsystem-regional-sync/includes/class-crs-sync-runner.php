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
    private const MAX_CONTEXT_ISSUES = 100;
    private const STALE_LOCK_TTL_SECONDS = 240;
    private const SOFT_RUN_LIMIT_SECONDS = 540;
    private const LOCK_HEARTBEAT_SECONDS = 15;
    private const PROGRESS_FLUSH_OBJECTS = 20;

    private Api_Client $client;
    private Logger $logger;
    private ?Regionalizer $regionalizer;
    private ?Lock $lock;
    private Sync_Map_Repository $mapRepository;
    private Media_Map_Repository $mediaMapRepository;
    private Media_Sync_Service $mediaSyncService;
    private Dependency_Check_Service $dependencyCheckService;
    /** @var float|null */
    private $runDeadline;
    private int $runLogId = 0;
    /** @var array<string,int> */
    private array $runProgress = [
        'checked_count' => 0,
        'updated_count' => 0,
        'created_count' => 0,
        'skipped_count' => 0,
        'error_count' => 0,
    ];
    /** @var float */
    private $lastLockHeartbeatAt = 0.0;
    private int $progressOpsSinceFlush = 0;

    public function __construct(
        Api_Client $client,
        Logger $logger,
        ?Regionalizer $regionalizer = null,
        ?Lock $lock = null,
        ?Sync_Map_Repository $mapRepository = null,
        ?Media_Map_Repository $mediaMapRepository = null,
        ?Media_Sync_Service $mediaSyncService = null,
        ?Dependency_Check_Service $dependencyCheckService = null
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->regionalizer = $regionalizer ?? new Regionalizer();
        $this->lock = $lock ?? new Lock();
        $this->mapRepository = $mapRepository ?? new Sync_Map_Repository();
        $this->mediaMapRepository = $mediaMapRepository ?? new Media_Map_Repository();
        $this->mediaSyncService = $mediaSyncService ?? new Media_Sync_Service();
        $this->dependencyCheckService = $dependencyCheckService ?? new Dependency_Check_Service();
    }

    public static function make(): self
    {
        return new self(new Api_Client(Settings::get()), new Logger());
    }

    public static function cleanup_stale_state(): bool
    {
        return self::make()->try_cleanup_stale_lock_and_running_runs();
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
        $summary = (new Primary_Regionalization_Runner($this->regionalizer))->run(Settings::get());

        $this->logger->finish($logId, [
            'status'        => (string) ($summary['status'] ?? 'success'),
            'checked_count' => (int) ($summary['checked_count'] ?? 0),
            'updated_count' => (int) ($summary['updated_count'] ?? 0),
            'skipped_count' => (int) ($summary['skipped_count'] ?? 0),
            'error_count'   => (int) ($summary['error_count'] ?? 0),
            'message'       => (string) ($summary['message'] ?? ''),
        ]);
    }

    public function run_sync(string $runType): void
    {
        if (! $this->lock->acquire($runType)) {
            $lockRecovered = $this->try_cleanup_stale_lock_and_running_runs();

            if (! $lockRecovered || ! $this->lock->acquire($runType)) {
                $logId = $this->logger->start($runType);
                $this->logger->finish($logId, [
                    'status'        => 'partial',
                    'skipped_count' => 1,
                    'message'       => 'Sync skipped: another sync run is active.',
                ]);
                return;
            }
        }

        $logId = $this->logger->start($runType);
        $this->begin_run_progress($logId);
        $this->runDeadline = microtime(true) + self::SOFT_RUN_LIMIT_SECONDS;
        register_shutdown_function(function () use ($logId): void {
            $this->handle_unexpected_run_shutdown($logId);
        });

        try {
            $settings = Settings::get();

            $categorySummary = $this->run_categories_sync($settings, $logId);
            $productSummary = $this->run_products_sync($settings, $logId);
            $pageSummary = $this->run_pages_sync($settings, $logId);
            $summary = $this->merge_sync_summaries($categorySummary, $productSummary, $pageSummary);

            $this->logger->finish($logId, $summary);
        } catch (\Throwable $e) {
            $isSoftTimeout = $this->is_soft_timeout_error($e->getMessage());
            $message = $this->sanitize_error_message($e->getMessage());

            if ($isSoftTimeout && $runType === 'manual') {
                $this->schedule_manual_continuation();
                $message .= ' Continuation was queued automatically.';
            }

            $this->logger->finish($logId, [
                'status'      => $isSoftTimeout ? 'partial' : 'error',
                'checked_count' => (int) ($this->runProgress['checked_count'] ?? 0),
                'updated_count' => (int) ($this->runProgress['updated_count'] ?? 0),
                'created_count' => (int) ($this->runProgress['created_count'] ?? 0),
                'skipped_count' => (int) ($this->runProgress['skipped_count'] ?? 0),
                'error_count' => max(1, (int) ($this->runProgress['error_count'] ?? 0)),
                'message'     => $message,
            ]);
        } finally {
            $this->lock->release();
            $this->runDeadline = null;
            $this->runLogId = 0;
            $this->progressOpsSinceFlush = 0;
        }
    }

    private function handle_unexpected_run_shutdown(int $logId): void
    {
        if ($logId <= 0) {
            return;
        }

        global $wpdb;

        $logRepository = new Sync_Log_Repository();
        $table = $logRepository->table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT status, finished_at FROM {$table} WHERE id = %d LIMIT 1", $logId),
            ARRAY_A
        );

        if (! is_array($row)) {
            return;
        }

        $status = (string) ($row['status'] ?? '');
        $finishedAt = (string) ($row['finished_at'] ?? '');

        if ($status !== 'running' || $finishedAt !== '') {
            return;
        }

        $message = 'Sync interrupted unexpectedly (shutdown guard).';
        $lastError = error_get_last();

        if (is_array($lastError) && isset($lastError['message'])) {
            $errorMessage = $this->sanitize_error_message((string) $lastError['message']);
            if ($errorMessage !== '') {
                $message .= ' ' . $errorMessage;
            }
        }

        $logRepository->update($logId, [
            'status'      => 'partial',
            'finished_at' => current_time('mysql', true),
            'message'     => $message,
        ]);

        delete_option(CRS_SYNC_LOCK_KEY);
    }

    private function try_cleanup_stale_lock_and_running_runs(): bool
    {
        $lockState = get_option(CRS_SYNC_LOCK_KEY, []);

        if (! is_array($lockState)) {
            return false;
        }

        $lockedAtRaw = (string) ($lockState['locked_at'] ?? '');
        $lockedAt = $lockedAtRaw !== '' ? strtotime($lockedAtRaw) : false;

        if (! $lockedAt) {
            return false;
        }

        if ((time() - $lockedAt) < self::STALE_LOCK_TTL_SECONDS) {
            return false;
        }

        $logRepository = new Sync_Log_Repository();
        $recentLogs = $logRepository->latest(200);
        $nowUtc = current_time('mysql', true);

        foreach ($recentLogs as $log) {
            if (! is_array($log)) {
                continue;
            }

            $status = (string) ($log['status'] ?? '');
            $runType = (string) ($log['run_type'] ?? '');
            $finishedAt = (string) ($log['finished_at'] ?? '');

            if ($status !== 'running' || ! in_array($runType, ['manual', 'cron'], true) || $finishedAt !== '') {
                continue;
            }

            $startedAtRaw = (string) ($log['started_at'] ?? '');
            $startedAt = $startedAtRaw !== '' ? strtotime($startedAtRaw) : false;

            if (! $startedAt || (time() - $startedAt) < self::STALE_LOCK_TTL_SECONDS) {
                continue;
            }

            $logRepository->update((int) ($log['id'] ?? 0), [
                'status'      => 'partial',
                'finished_at' => $nowUtc,
                'message'     => 'Sync interrupted (stale lock auto-cleanup).',
            ]);
        }

        delete_option(CRS_SYNC_LOCK_KEY);

        return true;
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

        $dependencyWarnings = array_merge(
            (array) ($categorySummary['dependency_warnings'] ?? []),
            (array) ($productSummary['dependency_warnings'] ?? []),
            (array) ($pageSummary['dependency_warnings'] ?? [])
        );
        $dependencyWarnings = array_values(array_unique(array_filter($dependencyWarnings, static function ($value): bool {
            return is_string($value) && $value !== '';
        })));

        if ($dependencyWarnings !== [] && $status !== 'error') {
            $status = 'partial';
        }

        $message = 'Categories, products and pages sync completed.';
        $message = $this->append_dependency_warnings_to_message($message, $dependencyWarnings);

        $context = [];
        $dependencyIssues = array_merge(
            (array) ($categorySummary['dependency_issues'] ?? []),
            (array) ($productSummary['dependency_issues'] ?? []),
            (array) ($pageSummary['dependency_issues'] ?? [])
        );
        $objectErrors = array_merge(
            (array) ($categorySummary['object_errors'] ?? []),
            (array) ($productSummary['object_errors'] ?? []),
            (array) ($pageSummary['object_errors'] ?? [])
        );

        if ($dependencyWarnings !== []) {
            $context['dependency_warnings'] = $dependencyWarnings;
        }
        if ($dependencyIssues !== []) {
            $context['dependency_issues'] = array_slice($dependencyIssues, 0, self::MAX_CONTEXT_ISSUES);
        }
        if ($objectErrors !== []) {
            $context['object_errors'] = array_slice($objectErrors, 0, self::MAX_CONTEXT_ISSUES);
        }

        $result = [
            'status'        => $status,
            'checked_count' => (int) ($categorySummary['checked_count'] ?? 0) + (int) ($productSummary['checked_count'] ?? 0) + (int) ($pageSummary['checked_count'] ?? 0),
            'updated_count' => (int) ($categorySummary['updated_count'] ?? 0) + (int) ($productSummary['updated_count'] ?? 0) + (int) ($pageSummary['updated_count'] ?? 0),
            'created_count' => (int) ($categorySummary['created_count'] ?? 0) + (int) ($productSummary['created_count'] ?? 0) + (int) ($pageSummary['created_count'] ?? 0),
            'skipped_count' => (int) ($categorySummary['skipped_count'] ?? 0) + (int) ($productSummary['skipped_count'] ?? 0) + (int) ($pageSummary['skipped_count'] ?? 0),
            'error_count'   => (int) ($categorySummary['error_count'] ?? 0) + (int) ($productSummary['error_count'] ?? 0) + (int) ($pageSummary['error_count'] ?? 0),
            'message'       => $message,
        ];

        if ($dependencyIssues !== [] || $objectErrors !== []) {
            $result['message'] .= sprintf(
                ' Object-level details: dependency=%d, errors=%d.',
                count($dependencyIssues),
                count($objectErrors)
            );
        }

        if ($context !== []) {
            $encodedContext = wp_json_encode($context);
            $result['context_json'] = is_string($encodedContext) ? $encodedContext : null;
        }

        return $result;
    }

    private function run_categories_sync(array $settings, int $logId): array
    {
        $summary = [
            'status'        => 'success',
            'checked_count' => 0,
            'updated_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'error_count'   => 0,
            'message'       => '',
            'dependency_issues' => [],
            'object_errors' => [],
        ];

        $seenRemoteIds = [];
        $pendingParentAssignments = [];
        $dependencyWarnings = [];
        $dependencyIssues = [];
        $stopRequested = false;
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $this->assert_run_time_budget('fetch_categories');

            try {
                $batch = $this->client->fetch_categories($page, self::CATEGORY_PER_PAGE);
            } catch (\Throwable $e) {
                $summary['status'] = 'partial';
                $this->increment_summary_counter($summary, 'error_count');
                $summary['message'] = 'Categories fetch failed on page ' . (int) $page . ': ' . sanitize_text_field($e->getMessage());
                break;
            }

            if (! is_array($batch) || $batch === []) {
                break;
            }

            usort($batch, static function (array $left, array $right): int {
                $leftParent = (int) ($left['parent'] ?? 0);
                $rightParent = (int) ($right['parent'] ?? 0);

                if ($leftParent === $rightParent) {
                    return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
                }

                return $leftParent <=> $rightParent;
            });

            foreach ($batch as $remoteCategory) {
                if (! is_array($remoteCategory)) {
                    continue;
                }

                $this->assert_run_time_budget('categories');
                $this->increment_summary_counter($summary, 'checked_count');

                try {
                    $this->collect_dependency_warnings(self::CATEGORY_OBJECT_TYPE, $remoteCategory, $dependencyWarnings, $dependencyIssues);
                    $result = $this->sync_single_category(
                        $remoteCategory,
                        $settings,
                        $seenRemoteIds,
                        $pendingParentAssignments
                    );

                    if ($result === 'created') {
                        $this->increment_summary_counter($summary, 'created_count');
                    } elseif ($result === 'updated') {
                        $this->increment_summary_counter($summary, 'updated_count');
                    } else {
                        $this->increment_summary_counter($summary, 'skipped_count');
                    }
                } catch (\Throwable $e) {
                    $this->increment_summary_counter($summary, 'error_count');
                    $errorMessage = $this->sanitize_error_message($e->getMessage());
                    $this->mark_category_sync_error($remoteCategory, $errorMessage);
                    $this->append_object_issue($summary['object_errors'], $this->build_object_issue(
                        self::CATEGORY_OBJECT_TYPE,
                        $remoteCategory,
                        $errorMessage
                    ));

                    if ($this->is_storage_quota_error($errorMessage)) {
                        $summary['status'] = 'partial';
                        $summary['message'] = 'Categories sync stopped: disk quota exceeded on regional uploads.';
                        $stopRequested = true;
                        break;
                    }
                }
            }

            $this->flush_run_progress('categories page ' . (int) $page, false);

            if ($stopRequested || count($batch) < self::CATEGORY_PER_PAGE) {
                break;
            }

            $page++;
        }

        $parentSyncResult = $this->apply_deferred_parent_assignments($pendingParentAssignments);
        $parentUpdated = (int) ($parentSyncResult['updated'] ?? 0);
        $parentErrors = (int) ($parentSyncResult['errors'] ?? 0);
        if ($parentUpdated > 0) {
            $this->increment_summary_counter($summary, 'updated_count', $parentUpdated);
        }
        if ($parentErrors > 0) {
            $this->increment_summary_counter($summary, 'error_count', $parentErrors);
        }

        $categoryUnpublished = (int) $this->apply_category_unpublish_logic($seenRemoteIds);
        if ($categoryUnpublished > 0) {
            $this->increment_summary_counter($summary, 'updated_count', $categoryUnpublished);
        }

        if ($summary['error_count'] > 0 || $dependencyWarnings !== []) {
            $summary['status'] = 'partial';
            $summary['message'] = 'Categories sync finished with partial errors.';
        } else {
            $summary['message'] = 'Categories sync completed successfully.';
        }

        $summary['message'] = $this->append_dependency_warnings_to_message($summary['message'], $dependencyWarnings);
        $summary['dependency_warnings'] = $dependencyWarnings;
        $summary['dependency_issues'] = $dependencyIssues;
        $this->flush_run_progress('categories complete', true);

        return $summary;
    }

    private function run_products_sync(array $settings, int $logId): array
    {
        $summary = [
            'status'        => 'success',
            'checked_count' => 0,
            'updated_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'error_count'   => 0,
            'message'       => '',
            'dependency_issues' => [],
            'object_errors' => [],
        ];

        $dictionary = Dictionary::parse((string) ($settings['replacement_dictionary'] ?? ''));

        $seenRemoteIds = [];
        $dependencyWarnings = [];
        $dependencyIssues = [];
        $stopRequested = false;
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $this->assert_run_time_budget('fetch_products');

            try {
                $batch = $this->client->fetch_products($page, self::PRODUCT_PER_PAGE);
            } catch (\Throwable $e) {
                $summary['status'] = 'partial';
                $this->increment_summary_counter($summary, 'error_count');
                $summary['message'] = 'Products fetch failed on page ' . (int) $page . ': ' . sanitize_text_field($e->getMessage());
                break;
            }

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $remoteProduct) {
                if (! is_array($remoteProduct)) {
                    continue;
                }

                $this->assert_run_time_budget('products');
                $this->increment_summary_counter($summary, 'checked_count');

                try {
                    $this->collect_dependency_warnings(self::PRODUCT_OBJECT_TYPE, $remoteProduct, $dependencyWarnings, $dependencyIssues);
                    $result = $this->sync_single_product($remoteProduct, $settings, $dictionary, $seenRemoteIds);

                    if ($result === 'created') {
                        $this->increment_summary_counter($summary, 'created_count');
                    } elseif ($result === 'updated') {
                        $this->increment_summary_counter($summary, 'updated_count');
                    } else {
                        $this->increment_summary_counter($summary, 'skipped_count');
                    }
                } catch (\Throwable $e) {
                    $this->increment_summary_counter($summary, 'error_count');
                    $errorMessage = $this->sanitize_error_message($e->getMessage());
                    $this->mark_product_sync_error($remoteProduct, $errorMessage);
                    $this->append_object_issue($summary['object_errors'], $this->build_object_issue(
                        self::PRODUCT_OBJECT_TYPE,
                        $remoteProduct,
                        $errorMessage
                    ));

                    if ($this->is_storage_quota_error($errorMessage)) {
                        $summary['status'] = 'partial';
                        $summary['message'] = 'Products sync stopped: disk quota exceeded on regional uploads.';
                        $stopRequested = true;
                        break;
                    }
                }
            }

            $this->flush_run_progress('products page ' . (int) $page, false);

            if ($stopRequested || count($batch) < self::PRODUCT_PER_PAGE) {
                break;
            }

            $page++;
        }

        $productUnpublished = (int) $this->apply_product_unpublish_logic($seenRemoteIds);
        if ($productUnpublished > 0) {
            $this->increment_summary_counter($summary, 'updated_count', $productUnpublished);
        }

        if ($summary['error_count'] > 0 || $dependencyWarnings !== []) {
            $summary['status'] = 'partial';
            $summary['message'] = 'Products sync finished with partial errors.';
        } else {
            $summary['message'] = 'Products sync completed successfully.';
        }

        $summary['message'] = $this->append_dependency_warnings_to_message($summary['message'], $dependencyWarnings);
        $summary['dependency_warnings'] = $dependencyWarnings;
        $summary['dependency_issues'] = $dependencyIssues;
        $this->flush_run_progress('products complete', true);

        return $summary;
    }

    private function run_pages_sync(array $settings, int $logId): array
    {
        $summary = [
            'status'        => 'success',
            'checked_count' => 0,
            'updated_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'error_count'   => 0,
            'message'       => '',
            'dependency_issues' => [],
            'object_errors' => [],
        ];

        $seenRemoteIds = [];
        $dependencyWarnings = [];
        $dependencyIssues = [];
        $stopRequested = false;
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $this->assert_run_time_budget('fetch_pages');

            try {
                $batch = $this->client->fetch_pages($page, self::PAGE_PER_PAGE);
            } catch (\Throwable $e) {
                $summary['status'] = 'partial';
                $this->increment_summary_counter($summary, 'error_count');
                $summary['message'] = 'Pages fetch failed on page ' . (int) $page . ': ' . sanitize_text_field($e->getMessage());
                break;
            }

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $remotePage) {
                if (! is_array($remotePage)) {
                    continue;
                }

                $this->assert_run_time_budget('pages');
                $this->increment_summary_counter($summary, 'checked_count');

                try {
                    $this->collect_dependency_warnings(self::PAGE_OBJECT_TYPE, $remotePage, $dependencyWarnings, $dependencyIssues);
                    $result = $this->sync_single_page($remotePage, $settings, $seenRemoteIds);

                    if ($result === 'created') {
                        $this->increment_summary_counter($summary, 'created_count');
                    } elseif ($result === 'updated') {
                        $this->increment_summary_counter($summary, 'updated_count');
                    } else {
                        $this->increment_summary_counter($summary, 'skipped_count');
                    }
                } catch (\Throwable $e) {
                    $this->increment_summary_counter($summary, 'error_count');
                    $errorMessage = $this->sanitize_error_message($e->getMessage());
                    $this->mark_page_sync_error($remotePage, $errorMessage);
                    $this->append_object_issue($summary['object_errors'], $this->build_object_issue(
                        self::PAGE_OBJECT_TYPE,
                        $remotePage,
                        $errorMessage
                    ));

                    if ($this->is_storage_quota_error($errorMessage)) {
                        $summary['status'] = 'partial';
                        $summary['message'] = 'Pages sync stopped: disk quota exceeded on regional uploads.';
                        $stopRequested = true;
                        break;
                    }
                }
            }

            $this->flush_run_progress('pages page ' . (int) $page, false);

            if ($stopRequested || count($batch) < self::PAGE_PER_PAGE) {
                break;
            }

            $page++;
        }

        $pageUnpublished = (int) $this->apply_page_unpublish_logic($seenRemoteIds);
        if ($pageUnpublished > 0) {
            $this->increment_summary_counter($summary, 'updated_count', $pageUnpublished);
        }

        if ($summary['error_count'] > 0 || $dependencyWarnings !== []) {
            $summary['status'] = 'partial';
            $summary['message'] = 'Pages sync finished with partial errors.';
        } else {
            $summary['message'] = 'Pages sync completed successfully.';
        }

        $summary['message'] = $this->append_dependency_warnings_to_message($summary['message'], $dependencyWarnings);
        $summary['dependency_warnings'] = $dependencyWarnings;
        $summary['dependency_issues'] = $dependencyIssues;
        $this->flush_run_progress('pages complete', true);

        return $summary;
    }

    private function begin_run_progress(int $logId): void
    {
        $this->runLogId = max(0, $logId);
        $this->runProgress = [
            'checked_count' => 0,
            'updated_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
        ];
        $this->lastLockHeartbeatAt = 0.0;
        $this->progressOpsSinceFlush = 0;
        $this->flush_run_progress('started', true);
    }

    private function increment_summary_counter(array &$summary, string $counterKey, int $delta = 1): void
    {
        if ($delta <= 0) {
            return;
        }

        $summary[$counterKey] = (int) ($summary[$counterKey] ?? 0) + $delta;
        $this->runProgress[$counterKey] = (int) ($this->runProgress[$counterKey] ?? 0) + $delta;
        $this->progressOpsSinceFlush += $delta;
    }

    private function flush_run_progress(string $stage, bool $force): void
    {
        if ($this->runLogId <= 0) {
            return;
        }

        if (! $force && $this->progressOpsSinceFlush < self::PROGRESS_FLUSH_OBJECTS) {
            return;
        }

        $message = 'Running: ' . sanitize_text_field($stage) . '.';
        (new Sync_Log_Repository())->update($this->runLogId, [
            'status' => 'running',
            'checked_count' => (int) ($this->runProgress['checked_count'] ?? 0),
            'updated_count' => (int) ($this->runProgress['updated_count'] ?? 0),
            'created_count' => (int) ($this->runProgress['created_count'] ?? 0),
            'skipped_count' => (int) ($this->runProgress['skipped_count'] ?? 0),
            'error_count' => (int) ($this->runProgress['error_count'] ?? 0),
            'message' => $message,
        ]);

        $this->progressOpsSinceFlush = 0;
    }

    private function assert_run_time_budget(string $scope): void
    {
        if (! is_float($this->runDeadline)) {
            return;
        }

        if (microtime(true) < $this->runDeadline) {
            $this->touch_lock_heartbeat();
            return;
        }

        throw new \RuntimeException('Sync soft timeout reached in ' . sanitize_key($scope) . '. Run is split and will continue on next launch.');
    }

    private function touch_lock_heartbeat(): void
    {
        $now = microtime(true);

        if (($now - (float) $this->lastLockHeartbeatAt) < self::LOCK_HEARTBEAT_SECONDS) {
            return;
        }

        $this->lock->touch();
        $this->lastLockHeartbeatAt = $now;
    }

    private function schedule_manual_continuation(): void
    {
        if (wp_next_scheduled(CRS_SYNC_MANUAL_CRON_HOOK) !== false) {
            return;
        }

        wp_schedule_single_event(time() + 15, CRS_SYNC_MANUAL_CRON_HOOK, []);

        if (function_exists('spawn_cron')) {
            spawn_cron();
            return;
        }

        wp_remote_post(site_url('wp-cron.php?doing_wp_cron=' . rawurlencode((string) microtime(true))), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    private function is_soft_timeout_error(string $message): bool
    {
        return strpos(strtolower($message), 'soft timeout reached') !== false;
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
        $this->mediaSyncService->sync_category_media($localTermId, $remoteId, $remoteCategory);
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
        $this->mediaSyncService->localize_product_media(
            $localPostId,
            $remoteId,
            (string) ($remoteProduct['description'] ?? ''),
            (string) ($remoteProduct['short_description'] ?? '')
        );
        $this->mediaSyncService->sync_product_media($localPostId, $remoteId, $remoteProduct);
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
        $this->mediaSyncService->localize_page_media(
            $localPostId,
            $remoteId,
            $this->extract_remote_page_text($remotePage, 'content')
        );
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
            'image_src'            => $this->extract_category_image_src($remoteCategory),
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

        $expectedImage = $this->extract_category_image_src($remoteCategory);
        $localThumbnailId = (int) get_term_meta((int) $localTerm->term_id, 'thumbnail_id', true);

        if ($expectedImage !== '' && $localThumbnailId <= 0) {
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

        if ($this->has_local_product_media_drift($localPost, $remoteProduct)) {
            return true;
        }

        $unpublishedFlag = (string) get_post_meta((int) $localPost->ID, self::UNPUBLISHED_META_KEY, true);

        return $unpublishedFlag !== '';
    }

    private function has_local_product_media_drift(\WP_Post $localPost, array $remoteProduct): bool
    {
        $remoteId = (int) ($remoteProduct['id'] ?? 0);
        $remoteImageUrls = $this->extract_remote_product_image_urls($remoteProduct);
        $remoteContentMediaUrls = $this->extract_media_urls_from_product_content($remoteProduct);

        if ($remoteImageUrls === [] && $remoteContentMediaUrls === []) {
            return false;
        }

        if ($remoteImageUrls !== []) {
            $thumbnailId = (int) get_post_thumbnail_id((int) $localPost->ID);

            if ($thumbnailId <= 0 || get_post_type($thumbnailId) !== 'attachment') {
                return true;
            }

            $expectedGalleryCount = max(0, count($remoteImageUrls) - 1);
            $rawGallery = trim((string) get_post_meta((int) $localPost->ID, '_product_image_gallery', true));
            $galleryIds = $rawGallery === '' ? [] : array_map('intval', explode(',', $rawGallery));
            $galleryIds = array_values(array_filter($galleryIds, static function (int $id): bool {
                return $id > 0 && get_post_type($id) === 'attachment';
            }));

            if (count($galleryIds) < $expectedGalleryCount) {
                return true;
            }
        }

        if ($remoteId <= 0 || $remoteContentMediaUrls === []) {
            return false;
        }

        foreach ($remoteContentMediaUrls as $mediaUrl) {
            $mappedMedia = $this->mediaMapRepository->find_by_remote_url(self::PRODUCT_OBJECT_TYPE, $remoteId, $mediaUrl);

            if (! is_array($mappedMedia)) {
                return true;
            }

            $attachmentId = (int) ($mappedMedia['local_attachment_id'] ?? 0);

            if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
                return true;
            }
        }

        return false;
    }

    private function extract_remote_product_image_urls(array $remoteProduct): array
    {
        $images = (array) ($remoteProduct['images'] ?? []);
        $urls = [];

        foreach ($images as $imageItem) {
            if (! is_array($imageItem)) {
                continue;
            }

            $url = esc_url_raw((string) ($imageItem['src'] ?? ''));

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function extract_media_urls_from_product_content(array $remoteProduct): array
    {
        $description = (string) ($remoteProduct['description'] ?? '');
        $shortDescription = (string) ($remoteProduct['short_description'] ?? '');

        $allUrls = array_merge(
            $this->extract_media_urls_from_html($description),
            $this->extract_media_urls_from_html($shortDescription)
        );

        return array_values(array_unique($allUrls));
    }

    private function extract_media_urls_from_html(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace('\\/', '/', $html);

        $urls = [];
        $attributeMatches = [];
        preg_match_all('/<(img|source|video|a)[^>]+(?:src|href|data-alt_link|data-src|data-url)=["\']([^"\']+)["\']/i', $html, $attributeMatches);

        if (isset($attributeMatches[2]) && is_array($attributeMatches[2])) {
            foreach ($attributeMatches[2] as $rawUrl) {
                $url = $this->sanitize_media_url_for_lookup((string) $rawUrl);

                if ($url === '') {
                    continue;
                }

                if ($this->is_supported_media_url($url)) {
                    $urls[] = $url;
                }
            }
        }

        $textMatches = [];
        preg_match_all('/https?:\/\/[^\s"\'<>]+/i', $html, $textMatches);

        if (isset($textMatches[0]) && is_array($textMatches[0])) {
            foreach ($textMatches[0] as $rawUrl) {
                $rawUrl = rtrim((string) $rawUrl, ".,);]");
                $url = $this->sanitize_media_url_for_lookup($rawUrl);

                if ($url === '') {
                    continue;
                }

                if ($this->is_supported_media_url($url)) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function is_supported_media_url(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($path === '') {
            return false;
        }

        if (strpos($path, '/wp-content/uploads/') !== false) {
            return true;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'mp4', 'webm', 'ogg', 'mov', 'm4v', 'avi'], true);
    }

    private function sanitize_media_url_for_lookup(string $rawUrl): string
    {
        $url = trim($rawUrl);

        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        $url = esc_url_raw($url);

        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);

        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $path === '') {
            return '';
        }

        $sourceHost = $this->extract_source_host();

        if ($sourceHost !== '' && $host !== $sourceHost && strpos($path, '/wp-content/uploads/') === 0) {
            $host = $sourceHost;
        }

        $normalized = $scheme . '://' . $host;

        if ($port > 0 && $port !== 80 && $port !== 443) {
            $normalized .= ':' . $port;
        }

        return $normalized . $path;
    }

    private function extract_source_host(): string
    {
        $settings = Settings::get();
        $host = parse_url((string) ($settings['source_url'] ?? ''), PHP_URL_HOST);

        return strtolower(is_string($host) ? $host : '');
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

    private function extract_category_image_src(array $remoteCategory): string
    {
        $image = $remoteCategory['image'] ?? null;

        if (! is_array($image)) {
            return '';
        }

        $src = esc_url_raw((string) ($image['src'] ?? ''));

        return is_string($src) ? $src : '';
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

    private function collect_dependency_warnings(string $objectType, array $remoteObject, array &$warnings, array &$issues): void
    {
        $missing = $this->dependencyCheckService->detect_missing_for_object($objectType, $remoteObject);

        if ($missing === []) {
            return;
        }

        $message = $this->dependencyCheckService->format_missing_message($missing);
        $warnings[] = $message;
        $warnings = array_values(array_unique($warnings));
        $this->append_object_issue($issues, array_merge(
            $this->build_object_issue($objectType, $remoteObject, $message),
            [
                'issue_type' => 'dependency',
                'missing_dependencies' => array_values($missing),
            ]
        ));
    }

    private function append_dependency_warnings_to_message(string $baseMessage, array $warnings): string
    {
        if ($warnings === []) {
            return $baseMessage;
        }

        $preview = array_slice($warnings, 0, 2);
        $suffix = implode(' | ', $preview);
        $extraCount = count($warnings) - count($preview);

        if ($extraCount > 0) {
            $suffix .= sprintf(' | and %d more dependency warning(s)', $extraCount);
        }

        return trim($baseMessage . ' ' . $suffix);
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
        $scopedMessage = $this->build_object_scoped_error_message(self::CATEGORY_OBJECT_TYPE, $remoteId, $slug, $message);

        $this->mapRepository->upsert([
            'object_type'           => self::CATEGORY_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localTerm instanceof \WP_Term ? (int) $localTerm->term_id : 0,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $this->extract_remote_modified_gmt($remoteCategory),
            'payload_hash'          => '',
            'last_operation_status' => 'error',
            'last_error_message'    => $scopedMessage,
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
        $scopedMessage = $this->build_object_scoped_error_message(self::PRODUCT_OBJECT_TYPE, $remoteId, $slug, $message);

        $this->mapRepository->upsert([
            'object_type'           => self::PRODUCT_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localPost instanceof \WP_Post ? (int) $localPost->ID : 0,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $this->extract_remote_modified_gmt($remoteProduct),
            'payload_hash'          => '',
            'last_operation_status' => 'error',
            'last_error_message'    => $scopedMessage,
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
        $scopedMessage = $this->build_object_scoped_error_message(self::PAGE_OBJECT_TYPE, $remoteId, $slug, $message);

        $this->mapRepository->upsert([
            'object_type'           => self::PAGE_OBJECT_TYPE,
            'remote_id'             => $remoteId,
            'local_id'              => $localPost instanceof \WP_Post ? (int) $localPost->ID : 0,
            'remote_slug'           => $slug,
            'remote_modified_gmt'   => $this->extract_remote_modified_gmt($remotePage),
            'payload_hash'          => '',
            'last_operation_status' => 'error',
            'last_error_message'    => $scopedMessage,
        ]);
    }

    private function build_object_issue(string $objectType, array $remoteObject, string $message): array
    {
        $remoteId = (int) ($remoteObject['id'] ?? 0);
        $slug = sanitize_title((string) ($remoteObject['slug'] ?? ''));

        return [
            'object_type' => $objectType,
            'remote_id'   => $remoteId,
            'remote_slug' => $slug,
            'message'     => $this->sanitize_error_message($message),
        ];
    }

    private function append_object_issue(array &$issues, array $issue): void
    {
        if (count($issues) >= self::MAX_CONTEXT_ISSUES) {
            return;
        }

        $issues[] = $issue;
    }

    private function build_object_scoped_error_message(string $objectType, int $remoteId, string $slug, string $message): string
    {
        $sanitizedMessage = $this->sanitize_error_message($message);
        $safeSlug = sanitize_title($slug);

        return sprintf(
            'Object sync error [%s id=%d slug=%s]: %s',
            sanitize_key($objectType),
            $remoteId,
            $safeSlug !== '' ? $safeSlug : '-',
            $sanitizedMessage
        );
    }

    private function is_storage_quota_error(string $message): bool
    {
        $lower = strtolower($message);

        return strpos($lower, 'quota exceeded') !== false
            || strpos($lower, 'errno=122') !== false
            || strpos($lower, 'no space left') !== false
            || strpos($lower, 'disk quota exceeded') !== false;
    }

    private function sanitize_error_message(string $message): string
    {
        $sanitized = preg_replace('/Authorization:\s*Basic\s+[A-Za-z0-9+\/=]+/i', 'Authorization: [REDACTED]', $message);
        $sanitized = preg_replace('/(api_application_password=)[^&\s]+/i', '$1[REDACTED]', (string) $sanitized);
        $sanitized = preg_replace('/(password=)[^&\s]+/i', '$1[REDACTED]', (string) $sanitized);
        $sanitized = sanitize_text_field((string) $sanitized);

        if ($sanitized === '') {
            return 'Unknown sync error.';
        }

        return substr($sanitized, 0, 500);
    }
}
