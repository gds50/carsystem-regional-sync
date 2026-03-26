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

        $time = Settings::sanitize_time((string) ($settings['sync_time'] ?? Settings::defaults()['sync_time']));
        $currentTimestamp = wp_next_scheduled(CRS_SYNC_CRON_HOOK);

        if ($currentTimestamp === false) {
            $nextTimestamp = Settings::next_run_timestamp($time);
            wp_schedule_event($nextTimestamp, 'daily', CRS_SYNC_CRON_HOOK);
            return;
        }

        // Compare the configured clock time instead of full timestamp.
        // This prevents skipping overdue daily events in system-cron mode:
        // due events must be executed by wp cron event run --due-now, not re-scheduled to tomorrow.
        $currentTime = wp_date('H:i', (int) $currentTimestamp, wp_timezone());

        if ($currentTime !== $time) {
            $nextTimestamp = Settings::next_run_timestamp($time);
            $this->unschedule();
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
