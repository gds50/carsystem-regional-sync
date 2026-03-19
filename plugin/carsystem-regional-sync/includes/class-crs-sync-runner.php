<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Runner
{
    public function __construct(
        private Api_Client $client,
        private Logger $logger,
        private ?Regionalizer $regionalizer = null,
        private ?Lock $lock = null,
    ) {
        $this->regionalizer = $this->regionalizer ?? new Regionalizer();
        $this->lock = $this->lock ?? new Lock();
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
            // TODO: implement categories -> products -> pages sync pipeline.
            $this->logger->finish($logId, [
                'status'        => 'success',
                'checked_count' => 0,
                'updated_count' => 0,
                'created_count' => 0,
                'skipped_count' => 0,
                'message'       => 'Sync skeleton executed. Implementation pending.',
            ]);
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
}
