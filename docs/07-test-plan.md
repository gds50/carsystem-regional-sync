# Тест-план MVP

## 1. Smoke tests

### Activation
- плагин активируется без fatal error
- таблицы создаются
- defaults создаются

### Access control
- `caradmin` видит меню
- другой администратор меню не видит
- прямой доступ к странице блокируется

### Settings
- URL сохраняется
- логин сохраняется
- password сохраняется
- region/city/area сохраняются
- replacement dictionary сохраняется и проходит базовую нормализацию
- partner name/phone/email/address сохраняются
- exclusions очищаются
- exclusions, введённые построчно в textarea, сохраняются как массив slug
- словарь корректно сохраняется

## 2. API tests

### Connection success
- валидный URL + login + app password
- `/users/me` отвечает 200
- в лог пишется success

### Connection fail
- неверный пароль
- неверный URL
- timeout
- пустой JSON / wp_error

## 3. Dictionary tests

- пустые строки игнорируются
- строка без `=>` игнорируется
- длинные фразы применяются раньше коротких
- негеографические слова не меняются без правила

## 4. Exclusions tests

- slug из defaults пропускается
- вручную добавленный slug пропускается
- удалённый из списка default slug снова участвует в sync

## 5. Primary regionalization tests

### Products
- меняется `seo_meta_title`
- меняется `seo_meta_description`
- не меняется title
- не меняется description
- не меняется short description

### Categories
- меняется `seo_meta_title`
- меняется `seo_h1`
- меняется `seo_meta_description`

## 6. Sync tests

### Categories
- новая категория создаётся
- изменённая категория обновляется
- поле `description` переносится с source
- поле `parent` переносится и иерархия категорий сохраняется
- поле `menu_order` переносится и порядок категорий в sidebar/widget совпадает с source
- снятая с публикации скрывается

### Products
- новый товар создаётся
- изменённый товар обновляется
- поле `short_description` переносится с source
- поля `price/regular_price/sale_price` переносятся
- поля `min_quantity/max_quantity/product_step` переносятся
- поля `weight/dimensions` переносятся
- категории привязываются
- SEO регионализируется после обновления
- снятый с публикации товар переводится в `draft` (без физического удаления)

### Pages
- новая страница создаётся
- изменённая страница обновляется
- exclusions работают
- регионализация не применяется

### Large volume run
- на тестовом наборе 300+ страниц run завершается без fatal error;
- синхронизация проходит по всем страницам пагинации, не только по первой;
- при ошибке на одной странице/объекте run завершается со статусом `partial`, а не аварийно;
- повторный run дозабирает ранее неуспешные объекты.

## 7. Lock tests

- второй запуск при активном lock не стартует
- протухший lock снимается

## 8. Logging tests

- создаётся запись запуска
- counters заполняются
- ошибки сохраняются без sensitive data
- для object-level dependency/media ошибок в `context_json` есть `object_type`, `remote_id`, `remote_slug`, `message`

## 9. Manual run tests

- `Запустить сейчас` работает только по nonce
- `Первичная регионализация` работает только по nonce
- ручной запуск использует тот же lock, что и cron
- во время активного lock повторный ручной запуск не стартует
- при активном lock создаётся log entry со статусом `partial` и сообщением о пропуске запуска
- кнопка `Run sync now` запускает полный путь sync (categories -> products -> pages)
- для full sync и primary regionalization отображаются отдельные статус-блоки на вкладке `Sync`
- индикатор `Latest action` показывает фактически последнее действие по максимальному `log id`
- блок расписания на вкладке `Sync` показывает `Auto sync`, `Sync time`, `Next scheduled run (UTC)`
- health-блок на вкладке `Sync` показывает `Cron mode`, `Lock` (status/run type/age), `Manual queue`
- `Run sync now` возвращает пользователя сразу (без долгого ожидания/504) и ставит full sync в background queue.
- при `DISABLE_WP_CRON=true` ручной запуск показывает состояние `scheduled` и не зависает в автопроверке статуса.
- если lock неактивен и в cron остались старые `crs_sync_manual_event`, они очищаются перед постановкой новой очереди.

## 10. PHP compatibility tests

- плагин активируется на PHP 7.4 без parse error;
- плагин активируется на PHP 8.1 и 8.3 без изменений кода;
- ручные действия (`Test connection`, `Primary regionalization`) работают на 7.4, 8.1, 8.3;
- таблицы persistence создаются на 7.4, 8.1, 8.3 при activation.

## 11. Post-MVP media sync tests

### Categories
- при создании новой категории переносится миниатюра (`thumbnail_id` не пустой);
- при обновлении категории смена картинки на source обновляет локальную миниатюру;
- при ошибке загрузки картинки run не падает целиком и фиксирует object-level ошибку.
- при включенном `Local media copy` и доступном filesystem path файл берётся локально без HTTP-загрузки.
- при выключенном `Local media copy` host normalization для media lookup не применяется (поведение HTTP-пути не меняется).

### Products
- при создании нового товара переносится главное изображение;
- при обновлении товара галерея изображений синхронизируется без дублей;
- при отсутствии картинки на source локальные медиа не удаляются физически без явного правила.

### Pages
- при наличии в `content` тегов `<img>/<video>/<source>` URL локализуются в attachment URL регионального сайта;
- при ошибке загрузки одного media-файла run не падает целиком и фиксирует object-level ошибку.

### Автоматизированные unit checks (текущее покрытие)
- `Media_Sync_Service`: извлечение media URL из `img/source/video/a` и `data-*` атрибутов;
- `Media_Sync_Service`: извлечение raw/escaped URL из текста и нормализация URL (без query/hash);
- `Media_Sync_Service`: нормализация VideoPack/kgvid markup в единый `[videopack ...]` shortcode;
- `Media_Sync_Service`: retry-policy для transient media ошибок (timeout/HTTP 429/5xx) и no-retry для постоянных ошибок (file-type/validation).

## 12. Dependency diagnostics tests

- если в product/category/page есть video-маркеры VideoPack, а плагин не установлен/не активен, в лог run попадает сообщение:
  - `Missing plugin dependency. Install and activate: VideoPack (...)`;
- при наличии VideoPack этот тип ошибок не создаётся;
- missing dependency на одном объекте не прерывает обработку остальных объектов.
