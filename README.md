# Carsystem Regional Sync Kit

Стартовый комплект для разработки WordPress-плагина региональной синхронизации сайта-копии `carsystem.su`.

## Что внутри

- `docs/` — ТЗ, архитектура, план этапов, API-контракты, безопасность, тест-план
- `plugin/carsystem-regional-sync/` — стартовый каркас плагина под WordPress / WooCommerce
- `CODEX.md` — инструкция для Codex / AI-агента
- `DEVELOPMENT_GUIDE.md` — как вести разработку по шагам
- `.github/workflows/php-lint.yml` — базовая CI-проверка
- `Makefile` — команды разработки

## Цель набора

Дать готовую основу, которую можно:

1. положить в GitHub-репозиторий;
2. открыть на сервере через Codex;
3. вести разработку итерациями без пересборки ТЗ с нуля.

## Предлагаемая упрощённая стратегия MVP

Вместо сложной распределённой схемы для первого релиза используем один плагин на региональном сайте с четырьмя понятными слоями:

1. **Admin UI** — настройки, кнопки запуска, логи.
2. **Remote API Client** — чтение данных основного сайта через WordPress / WooCommerce REST API.
3. **Sync Services** — синхронизация товаров, категорий, страниц.
4. **Persistence Layer** — хранение настроек, карты соответствия, логов.

Это проще сопровождать, чем перегруженную модульную систему, но архитектурно уже позволяет доращивать проект.

## Быстрый старт

```bash
make init
make lint
make package
```

Дальше:

1. создайте GitHub-репозиторий;
2. загрузите содержимое этого набора;
3. подключите Codex к репозиторию;
4. дайте Codex задачу начинать с `docs/06-implementation-plan.md` и `CODEX.md`.

## Политика совместимости PHP

- Плагин должен работать на `PHP 7.4+`.
- Разработка ведётся с приоритетом runtime-совместимости, даже если staging/локальная среда новее.
- Рекомендуемая матрица проверок: `PHP 7.4`, `PHP 8.1`, `PHP 8.3`.
- В коде нельзя использовать синтаксис, который ломает парсинг на PHP 7.4.

## Что уже заложено в каркас

- ограничение доступа только для пользователя `caradmin`;
- единая точка входа плагина;
- настройки, безопасная санитизация и nonce-паттерн;
- заготовка cron-расписания;
- клиент REST API для основного сайта;
- заготовки сервисов регионализации, синхронизации и логирования;
- предзаполненные исключения по slug.

## Что ещё нужно реализовать в разработке

- реальные обработчики сохранения / чтения SEO meta для продуктов и категорий;
- полная синхронизация create/update/unpublish;
- карта соответствия в отдельной таблице;
- журнал запусков в отдельной таблице;
- админский UI с рабочими экранами;
- тесты и smoke-check на staging.

## Актуальное состояние (main)

- ручной полный запуск синхронизации доступен на вкладке `Sync` кнопкой `Run sync now`;
- первичная регионализация остаётся отдельным ручным действием;
- в `Sync`-вкладке отображаются отдельные статус-блоки для:
  - полного sync (manual/cron),
  - primary regionalization;
- добавлен индикатор `Latest action` по последнему `log id`;
- в `Sync`-вкладке есть рабочая форма расписания:
  - `Enable auto sync`,
  - `Sync time`,
  - `Next scheduled run (UTC)`.
- в `Sync`-вкладке есть health-блок:
  - `Cron mode` (`traffic-triggered WP-Cron` / `system cron`),
  - `Lock` (status/run type/age),
  - `Manual queue` (queued timestamp или empty),
  - подсказка команды для Beget scheduler (`wp cron event run --due-now`).
- на вкладке `Connection` есть рабочая форма:
  - `Source URL`,
  - `API username`,
  - `Application password` (masked placeholder);
- на вкладках `Region`, `Partner`, `Exclusions` есть рабочие формы сохранения настроек.
- milestone `M12 (QA/hardening)` закрыт и зафиксирован в `docs/06-implementation-plan.md`.
- добавлен `M13` (media sync + dependency diagnostics):
  - новая таблица `crs_sync_media_map` для соответствий media;
  - синхронизация category image (`thumbnail_id`);
  - синхронизация category `menu_order` (term meta `order`) для совпадения порядка в sidebar/widget;
  - синхронизация product featured image + gallery из remote `images`;
  - локализация media URL в page content (`img/video/source`);
  - диагностика отсутствующего VideoPack с явной подсказкой в логах (`Install and activate ...`).
- `Run sync now` выполняется через background queue (single cron event), чтобы уменьшить риск `504` на `admin-post.php`.
- если включён `DISABLE_WP_CRON=true` (system cron mode), ручной запуск ставится в статус `scheduled` и стартует на следующем тике системного планировщика; автопуллинг UI для этого кейса отключён.
- при отсутствии активного lock плагин очищает зависшие события `crs_sync_manual_event`, чтобы избежать ложного `Manual sync is already queued`.
- для media sync добавлена опция `Local media copy` (same hosting/account) с fallback на HTTP.
- нормализация source-host для media lookup применяется только при включённом `Local media copy`; при выключенной галке HTTP-путь работает как раньше.
- dependency diagnostics расширен:
  - проверка отсутствующих плагинов для контента VideoPack и `Заказ/Оплата в 1 клик`;
  - исключения из контроля: `WooCommerce PayKeeper Plugin`, `WooCommerce - 1C (МойСклад, СБИС) - Data Exchange`;
  - email-оповещение администратору при обнаружении missing dependency (с cooldown).
- добавлен self-heal для product gallery media:
  - если у attachment отсутствует original файл, CRS пытается восстановить корректный `_wp_attached_file` (например без суффикса `-7`);
  - синхронизирует `wp_attachment_metadata[file]` с фактическим путём файла;
  - drift-check учитывает физическое наличие original file, чтобы автоматически чинить 404 в lightbox на следующих sync.
