<?php
/**
 * Milestone 3 admin shell.
 */
$tabs = [
    'connection' => __('Подключение', 'carsystem-regional-sync'),
    'region'     => __('Регион', 'carsystem-regional-sync'),
    'partner'    => __('Партнер', 'carsystem-regional-sync'),
    'exclusions' => __('Исключения', 'carsystem-regional-sync'),
    'sync'       => __('Синхронизация', 'carsystem-regional-sync'),
    'logs'       => __('Логи', 'carsystem-regional-sync'),
];

$activeTab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'connection';

if (! isset($tabs[$activeTab])) {
    $activeTab = 'connection';
}
?>
<div class="wrap">
    <h1><?php echo esc_html__('Региональная синхронизация Carsystem', 'carsystem-regional-sync'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $tabKey => $tabLabel) : ?>
            <?php
            $isActive = $tabKey === $activeTab;
            $tabUrl = add_query_arg(
                [
                    'page' => 'crs-sync',
                    'tab'  => $tabKey,
                ],
                admin_url('admin.php')
            );
            ?>
            <a href="<?php echo esc_url($tabUrl); ?>" class="nav-tab <?php echo $isActive ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tabLabel); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <div style="margin-top: 16px;">
        <?php if ($activeTab === 'connection') : ?>
            <?php
            $testStatus = (string) ($connectionTest['status'] ?? '');
            $testMessage = (string) ($connectionTest['message'] ?? '');
            $testedAt = (string) ($connectionTest['tested_at'] ?? '');
            $noticeClass = $testStatus === 'success' ? 'notice notice-success' : 'notice notice-error';
            $connectionSettings = \CRS\Settings::get();
            $sourceUrl = (string) ($connectionSettings['source_url'] ?? '');
            $apiUsername = (string) ($connectionSettings['api_username'] ?? '');
            $hasPassword = (string) ($connectionSettings['api_application_password'] ?? '') !== '';
            $useLocalMediaCopy = ! empty($connectionSettings['use_local_media_copy']);
            $sourceLocalBasePath = (string) ($connectionSettings['source_local_base_path'] ?? '');
            ?>

            <?php if ($testStatus !== '' && $testMessage !== '') : ?>
                <div class="<?php echo esc_attr($noticeClass); ?>" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html($testMessage); ?></strong></p>
                    <?php if ($testedAt !== '') : ?>
                        <p><?php echo esc_html(sprintf('Проверено (UTC): %s', $testedAt)); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php settings_errors('crs_sync_settings_group'); ?>

            <h2><?php echo esc_html__('Настройки подключения', 'carsystem-regional-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" style="margin-bottom: 16px;">
                <?php settings_fields('crs_sync_settings_group'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="crs-source-url"><?php echo esc_html__('Адрес источника (URL)', 'carsystem-regional-sync'); ?></label></th>
                        <td>
                            <input id="crs-source-url" type="url" class="regular-text" name="crs_sync_settings[source_url]" value="<?php echo esc_attr($sourceUrl); ?>" required>
                            <p class="description"><?php echo esc_html__('URL основного сайта, например https://carsystem.su', 'carsystem-regional-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-api-username"><?php echo esc_html__('Логин API', 'carsystem-regional-sync'); ?></label></th>
                        <td>
                            <input id="crs-api-username" type="text" class="regular-text" name="crs_sync_settings[api_username]" value="<?php echo esc_attr($apiUsername); ?>" autocomplete="off" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-api-password"><?php echo esc_html__('Пароль приложения', 'carsystem-regional-sync'); ?></label></th>
                        <td>
                            <input id="crs-api-password" type="password" class="regular-text" name="crs_sync_settings[api_application_password]" value="<?php echo esc_attr($hasPassword ? '********' : ''); ?>" autocomplete="new-password">
                            <p class="description"><?php echo esc_html__('Оставьте ********, чтобы сохранить текущий пароль. Введите новый, чтобы заменить.', 'carsystem-regional-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Режим локального копирования медиа', 'carsystem-regional-sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="crs_sync_settings[use_local_media_copy]" value="1" <?php checked($useLocalMediaCopy); ?>>
                                <?php echo esc_html__('Сначала копировать через локальную файловую систему (тот же хостинг-аккаунт), затем fallback на HTTP.', 'carsystem-regional-sync'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-source-local-base-path"><?php echo esc_html__('Локальный базовый путь источника (опционально)', 'carsystem-regional-sync'); ?></label></th>
                        <td>
                            <input id="crs-source-local-base-path" type="text" class="regular-text code" name="crs_sync_settings[source_local_base_path]" value="<?php echo esc_attr($sourceLocalBasePath); ?>" placeholder="/home/g/.../carsystem.su/public_html">
                            <p class="description"><?php echo esc_html__('Если поле пустое, плагин попробует определить путь по текущему ABSPATH и хосту Source URL.', 'carsystem-regional-sync'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Сохранить настройки подключения', 'carsystem-regional-sync')); ?>
            </form>

            <p><?php echo esc_html__('Используйте это действие, чтобы проверить доступ к удаленному WordPress REST API.', 'carsystem-regional-sync'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="crs_test_connection">
                <?php wp_nonce_field('crs_test_connection'); ?>
                <?php submit_button(__('Проверить подключение', 'carsystem-regional-sync')); ?>
            </form>
        <?php elseif ($activeTab === 'region') : ?>
            <?php
            $regionSettings = \CRS\Settings::get();
            $regionValue = (string) ($regionSettings['region'] ?? '');
            $cityValue = (string) ($regionSettings['city'] ?? '');
            $areaValue = (string) ($regionSettings['area'] ?? '');
            $dictionaryValue = (string) ($regionSettings['replacement_dictionary'] ?? '');
            ?>
            <?php settings_errors('crs_sync_settings_group'); ?>
            <h2><?php echo esc_html__('Настройки региона', 'carsystem-regional-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields('crs_sync_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="crs-region"><?php echo esc_html__('Регион', 'carsystem-regional-sync'); ?></label></th>
                        <td><input id="crs-region" type="text" class="regular-text" name="crs_sync_settings[region]" value="<?php echo esc_attr($regionValue); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-city"><?php echo esc_html__('Город', 'carsystem-regional-sync'); ?></label></th>
                        <td><input id="crs-city" type="text" class="regular-text" name="crs_sync_settings[city]" value="<?php echo esc_attr($cityValue); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-area"><?php echo esc_html__('Область', 'carsystem-regional-sync'); ?></label></th>
                        <td><input id="crs-area" type="text" class="regular-text" name="crs_sync_settings[area]" value="<?php echo esc_attr($areaValue); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-dictionary"><?php echo esc_html__('Словарь замен', 'carsystem-regional-sync'); ?></label></th>
                        <td>
                            <textarea id="crs-dictionary" class="large-text code" name="crs_sync_settings[replacement_dictionary]" rows="10"><?php echo esc_textarea($dictionaryValue); ?></textarea>
                            <p class="description"><?php echo esc_html__('Одно правило на строку: что_заменить => на_что_заменить', 'carsystem-regional-sync'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Сохранить настройки региона', 'carsystem-regional-sync')); ?>
            </form>
        <?php elseif ($activeTab === 'partner') : ?>
            <?php
            $partnerSettings = \CRS\Settings::get();
            $partnerName = (string) ($partnerSettings['partner_name'] ?? '');
            $partnerPhone = (string) ($partnerSettings['partner_phone'] ?? '');
            $partnerEmail = (string) ($partnerSettings['partner_email'] ?? '');
            $partnerAddress = (string) ($partnerSettings['partner_address'] ?? '');
            ?>
            <?php settings_errors('crs_sync_settings_group'); ?>
            <h2><?php echo esc_html__('Настройки партнера', 'carsystem-regional-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields('crs_sync_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="crs-partner-name"><?php echo esc_html__('Название партнера', 'carsystem-regional-sync'); ?></label></th>
                        <td><input id="crs-partner-name" type="text" class="regular-text" name="crs_sync_settings[partner_name]" value="<?php echo esc_attr($partnerName); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-partner-phone"><?php echo esc_html__('Телефон партнера', 'carsystem-regional-sync'); ?></label></th>
                        <td><input id="crs-partner-phone" type="text" class="regular-text" name="crs_sync_settings[partner_phone]" value="<?php echo esc_attr($partnerPhone); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-partner-email"><?php echo esc_html__('Эл. почта партнера', 'carsystem-regional-sync'); ?></label></th>
                        <td><input id="crs-partner-email" type="email" class="regular-text" name="crs_sync_settings[partner_email]" value="<?php echo esc_attr($partnerEmail); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-partner-address"><?php echo esc_html__('Адрес партнера', 'carsystem-regional-sync'); ?></label></th>
                        <td><textarea id="crs-partner-address" class="large-text" name="crs_sync_settings[partner_address]" rows="4"><?php echo esc_textarea($partnerAddress); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button(__('Сохранить настройки партнера', 'carsystem-regional-sync')); ?>
            </form>
        <?php elseif ($activeTab === 'exclusions') : ?>
            <?php
            $exclusionSettings = \CRS\Settings::get();
            $excludedSlugs = (array) ($exclusionSettings['excluded_slugs'] ?? []);
            $excludedProductLocalIds = array_map('intval', (array) ($exclusionSettings['excluded_product_local_ids'] ?? []));
            $excludedProductRemoteIds = array_map('intval', (array) ($exclusionSettings['excluded_product_remote_ids'] ?? []));
            $excludedText = implode("\n", array_map(static function ($slug) {
                return (string) $slug;
            }, $excludedSlugs));
            $excludedProductLabels = [];
            $mapRepository = new \CRS\Sync_Map_Repository();
            $excludedProductsLines = [];
            $displayItems = $excludedProductLocalIds;
            if ($displayItems === []) {
                foreach ($excludedProductRemoteIds as $remoteId) {
                    $remoteId = (int) $remoteId;
                    if ($remoteId <= 0) {
                        continue;
                    }

                    $mappingByRemote = $mapRepository->find_by_remote('product', $remoteId);
                    $localFromMap = is_array($mappingByRemote) ? (int) ($mappingByRemote['local_id'] ?? 0) : 0;
                    $displayItems[] = $localFromMap > 0 ? $localFromMap : $remoteId;
                }
            }

            foreach ($displayItems as $displayPostId) {
                $displayPostId = (int) $displayPostId;
                if ($displayPostId <= 0) {
                    continue;
                }

                $mappingByLocal = $mapRepository->find_by_local('product', $displayPostId);
                $remoteId = is_array($mappingByLocal) ? (int) ($mappingByLocal['remote_id'] ?? 0) : 0;

                if ($remoteId <= 0) {
                    $remoteId = $displayPostId;
                    $mappingByRemote = $mapRepository->find_by_remote('product', $remoteId);
                    if (is_array($mappingByRemote)) {
                        $displayPostId = (int) ($mappingByRemote['local_id'] ?? $displayPostId);
                    }
                }

                $excludedProductsLines[] = sprintf('post=%d', $displayPostId);
                $label = sprintf('post=%d -> remote=%d', $displayPostId, $remoteId);
                $localPostId = $displayPostId;

                if ($localPostId > 0) {
                    $localPost = get_post($localPostId);
                    if ($localPost instanceof \WP_Post) {
                        $title = sanitize_text_field((string) get_the_title($localPost));
                        if ($title === '') {
                            $title = '(без названия)';
                        }
                        $label .= sprintf(' - %s (локальный ID: %d)', $title, $localPost->ID);
                    } else {
                        $label .= sprintf(' - локальный ID %d не найден', $localPostId);
                    }
                } else {
                    $label .= ' - пока не синхронизирован локально';
                }

                $excludedProductLabels[] = $label;
            }
            $excludedProductsText = implode("\n", $excludedProductsLines);
            ?>
            <?php settings_errors('crs_sync_settings_group'); ?>
            <h2><?php echo esc_html__('Исключения', 'carsystem-regional-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields('crs_sync_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="crs-excluded-slugs"><?php echo esc_html__('Адрес страницы (по одному в строке)', 'carsystem-regional-sync'); ?></label></th>
                        <td>
                            <textarea id="crs-excluded-slugs" class="large-text code" name="crs_sync_settings[excluded_slugs]" rows="10"><?php echo esc_textarea($excludedText); ?></textarea>
                            <p class="description"><?php echo esc_html__('Исключенные slug пропускаются в синхронизации и первичной регионализации.', 'carsystem-regional-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-excluded-product-remote-ids"><?php echo esc_html__('Исключенные товары', 'carsystem-regional-sync'); ?></label></th>
                        <td>
                            <textarea id="crs-excluded-product-remote-ids" class="large-text code" name="crs_sync_settings[excluded_product_remote_ids]" rows="8"><?php echo esc_textarea($excludedProductsText); ?></textarea>
                            <p class="description"><?php echo esc_html__('Один элемент на строку. Можно указывать ID из URL товара в админке: post=5677 (или просто 5677). Плагин сам сопоставит локальный ID с remote ID и исключит товар из обновления.', 'carsystem-regional-sync'); ?></p>
                            <?php if ($excludedProductLabels !== []) : ?>
                                <p style="margin: 8px 0 4px 0;"><strong><?php echo esc_html__('Распознанные товары:', 'carsystem-regional-sync'); ?></strong></p>
                                <ul style="margin: 0; padding-left: 18px;">
                                    <?php foreach ($excludedProductLabels as $productLabel) : ?>
                                        <li><code><?php echo esc_html($productLabel); ?></code></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Сохранить исключения', 'carsystem-regional-sync')); ?>
            </form>
        <?php elseif ($activeTab === 'sync') : ?>
            <?php
            $latestPrimaryLog = null;
            $latestFullSyncLog = null;

            foreach ($logs as $logItem) {
                $runType = (string) ($logItem['run_type'] ?? '');

                if ($latestPrimaryLog === null && $runType === 'primary_regionalization') {
                    $latestPrimaryLog = $logItem;
                }

                if ($latestFullSyncLog === null && in_array($runType, ['manual', 'cron'], true)) {
                    $latestFullSyncLog = $logItem;
                }

                if ($latestPrimaryLog !== null && $latestFullSyncLog !== null) {
                    break;
                }
            }

            $latestPrimaryId = (int) ($latestPrimaryLog['id'] ?? 0);
            $latestSyncId = (int) ($latestFullSyncLog['id'] ?? 0);
            $lastActionLabel = '';
            $syncSettings = \CRS\Settings::get();
            $autoSyncEnabled = ! empty($syncSettings['auto_sync_enabled']);
            $syncTime = (string) ($syncSettings['sync_time'] ?? '02:30');
            $nextRunTimestamp = (new \CRS\Cron_Scheduler())->next_run_timestamp();
            $nextRunUtc = $nextRunTimestamp !== null ? gmdate('Y-m-d H:i:s', $nextRunTimestamp) : '';
            $isSystemCronMode = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $cronModeLabel = $isSystemCronMode ? 'системный cron' : 'WP-Cron (по трафику)';
            $lockState = get_option(CRS_SYNC_LOCK_KEY, []);
            $lockActive = (new \CRS\Lock())->is_active();
            $lockRunType = '';
            $lockAgeSeconds = null;
            if (is_array($lockState)) {
                $lockRunType = sanitize_key((string) ($lockState['run_type'] ?? ''));
                $lockAtRaw = (string) ($lockState['locked_at'] ?? '');
                $lockAtTs = $lockAtRaw !== '' ? strtotime($lockAtRaw) : false;
                if ($lockAtTs !== false) {
                    $lockAgeSeconds = max(0, time() - $lockAtTs);
                }
            }
            $manualEventTs = wp_next_scheduled(CRS_SYNC_MANUAL_CRON_HOOK);
            $manualEventUtc = $manualEventTs !== false ? gmdate('Y-m-d H:i:s', (int) $manualEventTs) : '';
            $manualQueueOverdueSeconds = null;
            $manualQueueIsOverdue = false;
            if ($manualEventTs !== false) {
                $manualQueueOverdueSeconds = max(0, time() - (int) $manualEventTs);
                $manualQueueIsOverdue = $manualQueueOverdueSeconds > 600;
            }
            $lastCronSuccessStartedAt = (string) ($latestCronSuccess['started_at'] ?? '');
            $lastCronSuccessFinishedAt = (string) ($latestCronSuccess['finished_at'] ?? '');
            $schedulerCommand = sprintf(
                'cd %s && /usr/local/bin/wp --allow-root cron event run --due-now',
                rtrim((string) ABSPATH, '/')
            );
            $syncQueuedState = isset($_GET['sync_queued']) ? sanitize_key((string) $_GET['sync_queued']) : '';
            $syncAutoPollEnabled = isset($_GET['sync_autopoll']) && sanitize_key((string) $_GET['sync_autopoll']) === '1';
            $syncRequestedTs = isset($_GET['sync_req_ts']) ? (int) $_GET['sync_req_ts'] : 0;
            $syncPollTry = isset($_GET['sync_poll_try']) ? (int) $_GET['sync_poll_try'] : 0;
            $latestSyncStatus = is_array($latestFullSyncLog) ? (string) ($latestFullSyncLog['status'] ?? '') : '';
            $latestSyncStartedRaw = is_array($latestFullSyncLog) ? (string) ($latestFullSyncLog['started_at'] ?? '') : '';
            $latestSyncStartedTs = $latestSyncStartedRaw !== '' ? (int) strtotime($latestSyncStartedRaw) : 0;
            $syncPollState = 'idle';
            $isRunningQueuedState = $syncQueuedState === 'active';

            if ($isRunningQueuedState && $latestSyncStatus === 'running') {
                $syncPollState = 'running';
            } elseif ($syncAutoPollEnabled && $syncRequestedTs > 0) {
                if ($latestSyncStartedTs <= 0 || $latestSyncStartedTs < $syncRequestedTs) {
                    $syncPollState = 'pending';
                } elseif ($latestSyncStatus === 'running') {
                    $syncPollState = 'running';
                } else {
                    $syncPollState = 'done';
                }
            }

            if ($latestPrimaryId > 0 || $latestSyncId > 0) {
                $lastActionLabel = $latestSyncId >= $latestPrimaryId
                    ? __('Полная синхронизация (Запустить сейчас)', 'carsystem-regional-sync')
                    : __('Первичная регионализация', 'carsystem-regional-sync');
            }
            ?>

            <?php settings_errors('crs_sync_settings_group'); ?>

            <?php if ($syncQueuedState === '1') : ?>
                <div class="notice notice-success" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html__('Синхронизация поставлена в фоновую очередь. Страница отвечает сразу, чтобы избежать таймаута шлюза.', 'carsystem-regional-sync'); ?></strong></p>
                </div>
            <?php elseif ($syncQueuedState === 'already') : ?>
                <div class="notice notice-warning" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html__('Ручная синхронизация уже в очереди. Дождитесь старта/завершения и обновите статус.', 'carsystem-regional-sync'); ?></strong></p>
                </div>
            <?php elseif ($syncQueuedState === 'active') : ?>
                <div class="notice notice-info" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html__('Синхронизация уже выполняется. Новый запуск не поставлен в очередь, чтобы избежать циклов пропуска из-за lock.', 'carsystem-regional-sync'); ?></strong></p>
                </div>
            <?php elseif ($syncQueuedState === 'scheduled') : ?>
                <div class="notice notice-info" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html__('Синхронизация поставлена в очередь system cron. Запуск будет на следующем тике планировщика.', 'carsystem-regional-sync'); ?></strong></p>
                </div>
            <?php elseif ($syncQueuedState === 'error') : ?>
                <div class="notice notice-error" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html__('Не удалось поставить фоновую синхронизацию в очередь. Проверьте cron-конфигурацию и логи.', 'carsystem-regional-sync'); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php if ($manualQueueIsOverdue && ! $lockActive) : ?>
                <div class="notice notice-warning" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html__('Ручная очередь просрочена и lock неактивен. Скорее всего system cron не выполнил WP-CLI команду.', 'carsystem-regional-sync'); ?></strong></p>
                    <p><?php echo esc_html(sprintf('Поставлено в очередь (UTC): %s | Просрочка: %dс', $manualEventUtc, (int) $manualQueueOverdueSeconds)); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($syncPollState === 'pending' || $syncPollState === 'running') : ?>
                <div id="crs-sync-autopoll-notice" class="notice notice-info" style="margin: 0 0 16px 0;">
                    <p id="crs-sync-autopoll-text">
                        <strong><?php echo esc_html($syncPollState === 'pending'
                                ? 'Синхронизация в очереди. Ожидание старта...'
                                : 'Синхронизация выполняется. Ожидание завершения...'); ?></strong>
                    </p>
                </div>
            <?php elseif ($syncPollState === 'done') : ?>
                <div class="notice notice-success" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html__('Синхронизация завершена. Статус полной синхронизации обновлен автоматически.', 'carsystem-regional-sync'); ?></strong></p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info" style="margin: 0 0 16px 0;">
                <p>
                    <strong><?php echo esc_html__('Автосинхронизация:', 'carsystem-regional-sync'); ?></strong>
                    <?php echo esc_html($autoSyncEnabled ? 'включена' : 'выключена'); ?>
                    |
                    <strong><?php echo esc_html__('Время синхронизации:', 'carsystem-regional-sync'); ?></strong>
                    <?php echo esc_html($syncTime); ?>
                    |
                    <strong><?php echo esc_html__('Следующий запуск по расписанию (UTC):', 'carsystem-regional-sync'); ?></strong>
                    <?php echo esc_html($nextRunUtc !== '' ? $nextRunUtc : __('не запланирован', 'carsystem-regional-sync')); ?>
                </p>
                <p>
                    <strong><?php echo esc_html__('Последний успешный cron-запуск (UTC):', 'carsystem-regional-sync'); ?></strong>
                    <?php if ($lastCronSuccessStartedAt !== '') : ?>
                        <?php echo esc_html($lastCronSuccessStartedAt); ?>
                        <?php if ($lastCronSuccessFinishedAt !== '') : ?>
                            <?php echo esc_html(sprintf(' -> %s', $lastCronSuccessFinishedAt)); ?>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php echo esc_html__('пока нет данных', 'carsystem-regional-sync'); ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="notice notice-info" style="margin: 0 0 16px 0;">
                <p>
                    <strong><?php echo esc_html__('Режим cron:', 'carsystem-regional-sync'); ?></strong>
                    <?php echo esc_html($cronModeLabel); ?>
                    |
                    <strong><?php echo esc_html__('Блокировка:', 'carsystem-regional-sync'); ?></strong>
                    <?php echo esc_html($lockActive ? 'активен' : 'свободен'); ?>
                    <?php if ($lockRunType !== '') : ?>
                        <?php echo esc_html(sprintf('(%s)', $lockRunType)); ?>
                    <?php endif; ?>
                    <?php if (is_int($lockAgeSeconds)) : ?>
                        <?php echo esc_html(sprintf('| возраст: %dс', $lockAgeSeconds)); ?>
                    <?php endif; ?>
                    |
                    <strong><?php echo esc_html__('Ручная очередь:', 'carsystem-regional-sync'); ?></strong>
                    <?php echo esc_html($manualEventUtc !== '' ? 'в очереди с ' . $manualEventUtc . ' UTC' : 'пусто'); ?>
                </p>
                <?php if (! $isSystemCronMode) : ?>
                    <p style="margin-top: 6px;">
                        <?php echo esc_html__('Для системного планировщика Beget используйте команду:', 'carsystem-regional-sync'); ?>
                        <code><?php echo esc_html($schedulerCommand); ?></code>
                    </p>
                    <p style="margin-top: 4px;">
                        <?php echo esc_html__('После активации планировщика установите DISABLE_WP_CRON=true в wp-config.php.', 'carsystem-regional-sync'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <h2><?php echo esc_html__('Настройки расписания', 'carsystem-regional-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" style="margin-bottom: 16px;">
                <?php settings_fields('crs_sync_settings_group'); ?>
                <input type="hidden" name="crs_sync_settings[source_url]" value="<?php echo esc_attr((string) ($syncSettings['source_url'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[api_username]" value="<?php echo esc_attr((string) ($syncSettings['api_username'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[api_application_password]" value="********">
                <input type="hidden" name="crs_sync_settings[region]" value="<?php echo esc_attr((string) ($syncSettings['region'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[city]" value="<?php echo esc_attr((string) ($syncSettings['city'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[area]" value="<?php echo esc_attr((string) ($syncSettings['area'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[replacement_dictionary]" value="<?php echo esc_attr((string) ($syncSettings['replacement_dictionary'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[partner_name]" value="<?php echo esc_attr((string) ($syncSettings['partner_name'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[partner_phone]" value="<?php echo esc_attr((string) ($syncSettings['partner_phone'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[partner_email]" value="<?php echo esc_attr((string) ($syncSettings['partner_email'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[partner_address]" value="<?php echo esc_attr((string) ($syncSettings['partner_address'] ?? '')); ?>">
                <input type="hidden" name="crs_sync_settings[use_local_media_copy]" value="<?php echo ! empty($syncSettings['use_local_media_copy']) ? '1' : '0'; ?>">
                <input type="hidden" name="crs_sync_settings[source_local_base_path]" value="<?php echo esc_attr((string) ($syncSettings['source_local_base_path'] ?? '')); ?>">
                <?php foreach ((array) ($syncSettings['excluded_slugs'] ?? []) as $excludedSlug) : ?>
                    <input type="hidden" name="crs_sync_settings[excluded_slugs][]" value="<?php echo esc_attr((string) $excludedSlug); ?>">
                <?php endforeach; ?>
                <?php foreach ((array) ($syncSettings['excluded_product_remote_ids'] ?? []) as $excludedProductRemoteId) : ?>
                    <input type="hidden" name="crs_sync_settings[excluded_product_remote_ids][]" value="<?php echo esc_attr((string) (int) $excludedProductRemoteId); ?>">
                <?php endforeach; ?>
                <?php foreach ((array) ($syncSettings['excluded_product_local_ids'] ?? []) as $excludedProductLocalId) : ?>
                    <input type="hidden" name="crs_sync_settings[excluded_product_local_ids][]" value="<?php echo esc_attr((string) (int) $excludedProductLocalId); ?>">
                <?php endforeach; ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Включить автосинхронизацию', 'carsystem-regional-sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="crs_sync_settings[auto_sync_enabled]" value="1" <?php checked($autoSyncEnabled); ?>>
                                <?php echo esc_html__('Запускать ежедневную синхронизацию по расписанию', 'carsystem-regional-sync'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crs-sync-time"><?php echo esc_html__('Время синхронизации', 'carsystem-regional-sync'); ?></label></th>
                        <td>
                            <input id="crs-sync-time" type="time" name="crs_sync_settings[sync_time]" value="<?php echo esc_attr($syncTime); ?>" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$">
                            <p class="description"><?php echo esc_html__('Время интерпретируется в часовом поясе сайта. Следующий запуск выше показан в UTC.', 'carsystem-regional-sync'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Сохранить расписание', 'carsystem-regional-sync')); ?>
            </form>

            <p><?php echo esc_html__('Запустить полную синхронизацию сейчас (категории, товары, страницы).', 'carsystem-regional-sync'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="crs_run_sync_now">
                <?php wp_nonce_field('crs_run_sync_now'); ?>
                <?php submit_button(__('Запустить синхронизацию', 'carsystem-regional-sync'), 'primary'); ?>
            </form>

            <p><?php echo esc_html__('Запустить первичную регионализацию для локальных товаров и категорий.', 'carsystem-regional-sync'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="crs_run_primary_regionalization">
                <?php wp_nonce_field('crs_run_primary_regionalization'); ?>
                <?php submit_button(__('Первичная регионализация', 'carsystem-regional-sync'), 'secondary'); ?>
            </form>

            <?php if ($lastActionLabel !== '') : ?>
                <p style="margin-top: 16px;"><strong><?php echo esc_html(sprintf('Последнее действие: %s', $lastActionLabel)); ?></strong></p>
            <?php endif; ?>

            <?php if (is_array($latestFullSyncLog)) : ?>
                <?php
                $syncStatus = (string) ($latestFullSyncLog['status'] ?? '');
                $syncMessage = (string) ($latestFullSyncLog['message'] ?? '');
                $syncStarted = (string) ($latestFullSyncLog['started_at'] ?? '');
                $syncFinished = (string) ($latestFullSyncLog['finished_at'] ?? '');
                $syncChecked = (int) ($latestFullSyncLog['checked_count'] ?? 0);
                $syncUpdated = (int) ($latestFullSyncLog['updated_count'] ?? 0);
                $syncCreated = (int) ($latestFullSyncLog['created_count'] ?? 0);
                $syncSkipped = (int) ($latestFullSyncLog['skipped_count'] ?? 0);
                $syncErrors = (int) ($latestFullSyncLog['error_count'] ?? 0);
                $syncNoticeClass = $syncStatus === 'success' ? 'notice notice-success' : 'notice notice-warning';
                ?>
                <div class="<?php echo esc_attr($syncNoticeClass); ?>" style="margin: 16px 0 0 0;">
                    <p><strong><?php echo esc_html(sprintf('Статус полной синхронизации: %s', $syncStatus)); ?></strong></p>
                    <?php if ($syncMessage !== '') : ?>
                        <p><?php echo esc_html($syncMessage); ?></p>
                    <?php endif; ?>
                    <p><?php echo esc_html(sprintf('Проверено: %d | Обновлено: %d | Создано: %d | Пропущено: %d | Ошибок: %d', $syncChecked, $syncUpdated, $syncCreated, $syncSkipped, $syncErrors)); ?></p>
                    <?php if ($syncStarted !== '' || $syncFinished !== '') : ?>
                        <p><?php echo esc_html(sprintf('Старт (UTC): %s | Финиш (UTC): %s', $syncStarted, $syncFinished)); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (is_array($latestPrimaryLog) || (string) ($primaryRegionalization['status'] ?? '') !== '') : ?>
                <?php
                $primaryData = is_array($latestPrimaryLog) ? $latestPrimaryLog : $primaryRegionalization;
                $runStatus = (string) ($primaryData['status'] ?? '');
                $runMessage = (string) ($primaryData['message'] ?? '');
                $runStarted = (string) ($primaryData['started_at'] ?? '');
                $runFinished = (string) ($primaryData['finished_at'] ?? '');
                $runChecked = (int) ($primaryData['checked_count'] ?? 0);
                $runUpdated = (int) ($primaryData['updated_count'] ?? 0);
                $runCreated = (int) ($primaryData['created_count'] ?? 0);
                $runSkipped = (int) ($primaryData['skipped_count'] ?? 0);
                $runErrors = (int) ($primaryData['error_count'] ?? 0);
                $runNoticeClass = $runStatus === 'success' ? 'notice notice-success' : 'notice notice-warning';
                ?>
                <div class="<?php echo esc_attr($runNoticeClass); ?>" style="margin: 16px 0 0 0;">
                    <p><strong><?php echo esc_html(sprintf('Статус первичной регионализации: %s', $runStatus)); ?></strong></p>
                    <?php if ($runMessage !== '') : ?>
                        <p><?php echo esc_html($runMessage); ?></p>
                    <?php endif; ?>
                    <p><?php echo esc_html(sprintf('Проверено: %d | Обновлено: %d | Создано: %d | Пропущено: %d | Ошибок: %d', $runChecked, $runUpdated, $runCreated, $runSkipped, $runErrors)); ?></p>
                    <?php if ($runStarted !== '' || $runFinished !== '') : ?>
                        <p><?php echo esc_html(sprintf('Старт (UTC): %s | Финиш (UTC): %s', $runStarted, $runFinished)); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (($syncPollState === 'pending' || $syncPollState === 'running') && $syncPollTry < 40) : ?>
                <?php
                $nextPollUrl = add_query_arg([
                    'page' => 'crs-sync',
                    'tab' => 'sync',
                    'sync_autopoll' => '1',
                    'sync_queued' => $syncQueuedState,
                    'sync_req_ts' => (string) $syncRequestedTs,
                    'sync_poll_try' => (string) ($syncPollTry + 1),
                ], admin_url('admin.php'));
                ?>
                <script>
                    (function() {
                        var textNode = document.getElementById('crs-sync-autopoll-text');
                        if (textNode) {
                            textNode.textContent = 'Автопроверка выполняется... попытка <?php echo (int) ($syncPollTry + 1); ?> из 40.';
                        }
                        window.setTimeout(function() {
                            window.location.href = <?php echo wp_json_encode($nextPollUrl); ?>;
                        }, 8000);
                    })();
                </script>
            <?php elseif (($syncPollState === 'pending' || $syncPollState === 'running') && $syncPollTry >= 40) : ?>
                <div class="notice notice-warning" style="margin: 16px 0 0 0;">
                    <p><strong><?php echo esc_html__('Достигнут таймаут автопроверки. Обновите страницу вручную.', 'carsystem-regional-sync'); ?></strong></p>
                </div>
            <?php endif; ?>
        <?php elseif ($activeTab === 'logs') : ?>
            <?php if ($logs === []) : ?>
                <p><?php echo esc_html__('Логов пока нет.', 'carsystem-regional-sync'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('ID', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Тип запуска', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Статус', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Старт (UTC)', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Финиш (UTC)', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Проверено', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Обновлено', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Создано', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Пропущено', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Ошибки', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Сообщение', 'carsystem-regional-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ($log['id'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['run_type'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['status'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['started_at'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['finished_at'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['checked_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['updated_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['created_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['skipped_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['error_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['message'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
