<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Lock
{
    private const TTL = 7200;

    public function acquire(string $runType): bool
    {
        $current = get_option(CRS_SYNC_LOCK_KEY, []);

        if (is_array($current) && ! empty($current['locked_at'])) {
            $lockedAt = strtotime((string) $current['locked_at']);

            if ($lockedAt && (time() - $lockedAt) < self::TTL) {
                return false;
            }
        }

        update_option(CRS_SYNC_LOCK_KEY, [
            'locked_at' => current_time('mysql', true),
            'run_type'  => $runType,
        ], false);

        return true;
    }

    public function release(): void
    {
        delete_option(CRS_SYNC_LOCK_KEY);
    }

    public function touch(?string $runType = null): void
    {
        $current = get_option(CRS_SYNC_LOCK_KEY, []);

        if (! is_array($current) || empty($current['locked_at'])) {
            return;
        }

        $activeRunType = (string) ($current['run_type'] ?? '');

        update_option(CRS_SYNC_LOCK_KEY, [
            'locked_at' => current_time('mysql', true),
            'run_type'  => $runType !== null && $runType !== '' ? $runType : $activeRunType,
        ], false);
    }

    public function is_active(): bool
    {
        $current = get_option(CRS_SYNC_LOCK_KEY, []);

        if (! is_array($current) || empty($current['locked_at'])) {
            return false;
        }

        $lockedAt = strtotime((string) $current['locked_at']);

        return $lockedAt !== false && (time() - $lockedAt) < self::TTL;
    }
}
