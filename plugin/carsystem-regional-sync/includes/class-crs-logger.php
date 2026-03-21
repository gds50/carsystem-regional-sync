<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Logger
{
    public function start(string $runType): int
    {
        $repository = new Sync_Log_Repository();
        $repository->maybe_cleanup_old_logs();

        return $repository->insert([
            'run_type'        => $runType,
            'started_at'      => current_time('mysql', true),
            'finished_at'     => null,
            'status'          => 'running',
            'checked_count'   => 0,
            'updated_count'   => 0,
            'created_count'   => 0,
            'skipped_count'   => 0,
            'error_count'     => 0,
            'message'         => '',
            'context_json'    => null,
        ]);
    }

    public function finish(int $logId, array $data): void
    {
        $payload = array_merge($data, [
            'finished_at' => current_time('mysql', true),
        ]);

        (new Sync_Log_Repository())->update($logId, $payload);
    }
}
