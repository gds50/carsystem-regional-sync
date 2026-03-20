# План реализации

## Milestone 1 — Bootstrap

Сделать:
- файл плагина;
- константы;
- автозагрузку файлов;
- основной класс;
- activation / deactivation hooks.

Результат:
- плагин активируется без ошибок.

## Milestone 2 — Settings storage

Сделать:
- `register_setting()`;
- defaults;
- sanitize callback;
- helper доступа к настройкам.

Результат:
- настройки сохраняются безопасно.

## Milestone 3 — Admin UI shell

Сделать:
- меню в админке;
- вкладки: подключение, регион, партнёр, исключения, синхронизация, логи;
- рабочие формы сохранения настроек на вкладках `Connection`, `Region`, `Partner`, `Exclusions` через `options.php`;
- скрытие меню от всех кроме `caradmin`.

Результат:
- пользователь `caradmin` видит рабочий экран.

## Milestone 4 — Connection test

Сделать:
- форму ввода параметров подключения (`source_url`, `api_username`, `api_application_password`);
- кнопку проверки соединения;
- запрос к `/wp-json/wp/v2/users/me`;
- обработку ошибок;
- запись результата в лог.

Результат:
- можно убедиться, что API доступен.

## Milestone 5 — Dictionary parser + exclusions

Сделать:
- парсер строк `from => to`;
- сортировку по длине ключа;
- helper `is_excluded_slug()`.

Результат:
- словарь и исключения готовы к боевой логике.

## Milestone 6 — Primary regionalization

Сделать:
- сервис обхода локальных товаров;
- сервис обхода локальных категорий;
- чтение только разрешённых SEO-полей;
- сохранение только если значение изменилось;
- журнал результата.

Результат:
- ручная первичная регионализация работает.

## Milestone 7 — Persistence tables

Сделать:
- таблица `crs_sync_map`;
- таблица `crs_sync_logs`;
- repository-классы.

Результат:
- плагин умеет хранить sync state.

## Milestone 8 — Categories sync

Сделать:
- чтение remote категорий;
- create/update локальных категорий;
- синхронизацию `description`;
- синхронизацию `parent` по mapping (с корректной иерархией);
- сохранение SEO meta;
- mapping update;
- unpublish logic.

Результат:
- категории синхронизируются первыми.

## Milestone 9 — Products sync

Сделать:
- чтение remote товаров;
- create/update локальных товаров;
- перенос ключевых продуктовых полей из API: `description`, `short_description`, `sku`, `price/regular_price/sale_price`, `min_quantity/max_quantity/product_step`, `weight`, `dimensions`, `tax_status/tax_class`, `catalog_visibility`, `menu_order`, `type`, `attributes/tags/downloads` (как payload в тех. meta);
- привязка категорий по mapping;
- сохранение SEO meta;
- повторная регионализация SEO.
- drift-check локального товара по значимым полям, чтобы не пропускать рассинхрон;
- unpublish logic для недоступных/непубличных remote товаров.

Результат:
- товары синхронизируются.

## Milestone 10 — Pages sync

Сделать:
- чтение remote страниц;
- применение exclusions;
- create/update локальных страниц;
- без регионализации.

Результат:
- страницы синхронизируются.

## Milestone 11 — Scheduled sync

Сделать:
- cron schedule;
- расчёт ближайшего времени запуска;
- включение / выключение из настроек;
- ручная кнопка `Запустить сейчас`;
- ручной запуск из вкладки `Sync` через отдельный action `admin_post_crs_run_sync_now` (nonce + проверки `caradmin`);
- отдельная кнопка `Primary regionalization` как независимое ручное действие;
- отдельные статус-блоки на вкладке `Sync` для full sync и primary regionalization;
- индикатор `Latest action` по последней записи в логах;
- операционный блок с `Auto sync`, `Sync time`, `Next scheduled run (UTC)`;
- health-блок в `Sync`:
  - `Cron mode`,
  - `Lock` (status/run_type/age),
  - `Manual queue` state;
- lock.
- ограничение `per_page` и безопасный обход всей пагинации;
- ограниченный retry для 429/5xx с backoff;
- статус run `partial`, если были частичные ошибки.

Результат:
- ночная синхронизация работает автоматически, а ручной запуск позволяет сразу принудительно выполнить sync без ожидания ночи.

## Milestone 12 — QA / hardening

Сделать:
- проверка повторного запуска;
- проверка bad API responses;
- sanitization audit;
- capability audit;
- логирование частичных ошибок.
- проверка сценария 300+ страниц без фаталов и без переполнения памяти;
- проверка, что после частичного падения следующий run дозавершает обновления.
- compatibility audit на PHP 7.4 / 8.1 / 8.3;
- удаление/замена PHP 8-only конструкций, ломающих runtime на 7.4.

Результат:
- MVP готов к staging.

Статус:
- ✅ Завершён (staging verification).

Что подтверждено:
- lock-механизм блокирует параллельный запуск и пишет `partial`-log с причиной пропуска;
- обработка bad API settings даёт валидируемые ошибки (`source_url`, `api_username`, `api_application_password`);
- object-level ошибки пишутся без sensitive данных (с маскированием потенциальных секретов);
- путь `Sync_Runner::run_primary_regionalization()` переведён с TODO-скелета на реальный раннер;
- выполнен lint на staging для затронутых файлов на `PHP 8.1` и `PHP 7.4`;
- smoke-проверки через WP-CLI показали ожидаемые статусы `success/partial`.

## Post-MVP — Media sync (categories/products)

Сделать:
- синхронизацию миниатюр категорий (`image` -> `thumbnail_id`) при create/update;
- синхронизацию изображений товаров (featured/gallery) при create/update;
- безопасную загрузку media с retry и логированием ошибок по объектам;
- хранение связи remote media -> local attachment для повторных run без дублей.

Результат:
- новые и обновлённые категории/товары переносятся вместе с изображениями.

## Milestone 13 — Media sync + dependency diagnostics

Сделать:
- добавить таблицу `crs_sync_media_map` для соответствия remote media URL -> local attachment;
- синхронизировать category image в `thumbnail_id`;
- синхронизировать product featured image + gallery;
- локализовать media URL в page content (`img/video/source`) через локальные attachment URL;
- добавить pre-check зависимостей для видео (VideoPack) и писать в лог явное сообщение `Install and activate ...`, если плагин отсутствует;
- завершать run статусом `partial`, если есть object-level media/dependency ошибки, без остановки всего run.

Результат:
- при добавлении/обновлении фото и видео на source файлы перекачиваются на региональный сайт;
- при отсутствии нужного плагина в логах есть явная инструкция, какой плагин установить.

Дополнение (ops):
- ручной `Run sync now` выполняется через background queue (single cron event), чтобы не упираться в `504` на `admin-post.php`;
- для media sync добавлен опциональный режим `Local media copy` (для source/region на одном аккаунте), с HTTP fallback.
