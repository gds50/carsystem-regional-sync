<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Cron_Scheduler
{
    public function ensure_scheduled(array $settings): void
    {
        if (empty($settings['auto_sync_enabled'])) {
            $this->unschedule();
            return;
        }

        $time = (string) ($settings['sync_time'] ?? Settings::defaults()['sync_time']);
        $nextTimestamp = Settings::next_run_timestamp($time);
        $currentTimestamp = wp_next_scheduled(CRS_SYNC_CRON_HOOK);

        if ($currentTimestamp === false) {
            wp_schedule_event($nextTimestamp, 'daily', CRS_SYNC_CRON_HOOK);
            return;
        }

        if ((int) $currentTimestamp !== (int) $nextTimestamp) {
            wp_unschedule_event((int) $currentTimestamp, CRS_SYNC_CRON_HOOK);
            wp_schedule_event($nextTimestamp, 'daily', CRS_SYNC_CRON_HOOK);
        }
    }

    public function unschedule(): void
    {
        $currentTimestamp = wp_next_scheduled(CRS_SYNC_CRON_HOOK);

        while ($currentTimestamp !== false) {
            wp_unschedule_event((int) $currentTimestamp, CRS_SYNC_CRON_HOOK);
            $currentTimestamp = wp_next_scheduled(CRS_SYNC_CRON_HOOK);
        }
    }

    public function next_run_timestamp(): ?int
    {
        $timestamp = wp_next_scheduled(CRS_SYNC_CRON_HOOK);

        return $timestamp === false ? null : (int) $timestamp;
    }
}
