# API-контракт MVP

## 1. Аутентификация

Источник данных: `https://carsystem.su`

Авторизация:
- WordPress Application Passwords
- Basic Auth
- HTTPS only

## 2. Эндпоинты

### 2.1 Проверка соединения
- `GET /wp-json/wp/v2/users/me`

Ожидание:
- успешный ответ
- username совпадает с ожидаемым техническим пользователем или хотя бы возвращается валидный пользователь

### 2.2 Товары WooCommerce
- `GET /wp-json/wc/v3/products`

Нужные поля:
- `id`
- `name`
- `slug`
- `status`
- `description`
- `short_description`
- `sku`
- `price`
- `regular_price`
- `sale_price`
- `weight`
- `dimensions`
- `tax_status`
- `tax_class`
- `catalog_visibility`
- `menu_order`
- `type`
- `attributes`
- `tags`
- `downloads`
- `meta_data.min_quantity`
- `meta_data.max_quantity`
- `meta_data.product_step`
- `categories`
- `images` (для post-MVP media sync)
- `date_modified_gmt`
- `meta_data`

Из `meta_data` использовать:
- `seo_meta_title`
- `seo_meta_description`

### 2.3 Категории WooCommerce
- `GET /wp-json/wc/v3/products/categories`

Нужные поля:
- `id`
- `name`
- `slug`
- `description`
- `parent`
- `count`
- `display`
- `menu_order`
- `image`
- `date_modified_gmt`, если доступно
- SEO meta поля, подтверждённые вашим API

Из API использовать:
- `seo_meta_title`
- `seo_h1`
- `seo_meta_description`

Примечание по scope:
- в MVP для категорий обязательны `name/slug/description/parent` и SEO-поля;
- поле `image` резервируется для post-MVP media sync (перенос миниатюр).

### 2.4 Обычные страницы
- `GET /wp-json/wp/v2/pages`

Нужные поля:
- `id`
- `slug`
- `status`
- `title.rendered`
- `content.rendered`
- `excerpt.rendered`
- `modified_gmt`

## 3. Пагинация

Для всех коллекций закладываем поддержку:
- `page`
- `per_page`

Плагин должен уметь забирать все страницы результатов по кругу.

Рекомендации для стабильной работы на сайтах с сотнями объектов:
- `per_page` по умолчанию `50`;
- верхняя граница `per_page` — `100`;
- завершать обход коллекции, когда получено меньше элементов, чем `per_page`;
- жёсткий предохранитель от бесконечного цикла: не более `1000` страниц пагинации на один run.

## 4. Ошибки API

Нужно обрабатывать:
- 401 / 403 — ошибка авторизации;
- 404 — endpoint недоступен;
- 429 — временное ограничение;
- 5xx — ошибка сервера;
- пустой или некорректный JSON.

Для 429 и 5xx нужен ограниченный retry:
- до `3` попыток на запрос;
- backoff `1s`, `2s`, `4s`;
- если все попытки неуспешны, фиксировать ошибку в логе run и переходить к следующему объекту/странице.

## 5. Сетевые правила

- использовать `wp_remote_get()`;
- задавать timeout не менее 20 секунд;
- использовать понятный `user-agent`;
- не логировать `Authorization` header;
- в лог писать только код ошибки и краткое описание.

## 6. Политика обновления объектов

### Product / category
Если remote payload изменился:
- обновить локальный объект;
- затем выполнить словарную регионализацию SEO-полей.

Для `category` в MVP обязательно синхронизировать `parent` и `description`, чтобы не ломалась иерархия и контент.
Для `product` в MVP обязательно синхронизировать расширенный набор полей (включая `short_description`, цены, `min_quantity/max_quantity/product_step`, вес/габариты, tax и category mapping).
Контроль остатков (`manage_stock/stock_quantity/stock_status`) в текущем scope отключён и не синхронизируется.

### Page
Если remote payload изменился:
- обновить локальную страницу;
- без регионализации.

Дополнительно:
- обновлять объект только если реально изменились значимые поля;
- если обновление конкретного объекта завершилось ошибкой, run не прерывать целиком;
- ошибка конкретного объекта должна попадать в лог и счётчик ошибок.

## 7. Политика недоступных объектов

Если объект перестал быть опубликованным или недоступен через API:
- не удалять локально;
- переводить в draft / hidden state, где это возможно.
