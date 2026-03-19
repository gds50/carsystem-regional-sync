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
- `categories`
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

## 4. Ошибки API

Нужно обрабатывать:
- 401 / 403 — ошибка авторизации;
- 404 — endpoint недоступен;
- 429 — временное ограничение;
- 5xx — ошибка сервера;
- пустой или некорректный JSON.

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

### Page
Если remote payload изменился:
- обновить локальную страницу;
- без регионализации.

## 7. Политика недоступных объектов

Если объект перестал быть опубликованным или недоступен через API:
- не удалять локально;
- переводить в draft / hidden state, где это возможно.
