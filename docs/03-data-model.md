# Модель данных

## 1. Option: `crs_sync_settings`

Хранится одной опцией как массив.

```php
[
    'source_url' => 'https://carsystem.su',
    'api_username' => 'api_sync_reader',
    'api_application_password' => '',
    'region' => 'Тюменская область',
    'city' => 'Тюмень',
    'area' => 'Тюменская область',
    'replacement_dictionary' => "Москва => Тюмень\n...",
    'partner_name' => '',
    'partner_phone' => '',
    'partner_email' => '',
    'partner_address' => '',
    'excluded_slugs' => [
        'gde-kupit',
        'dostavka',
        'privacy-policy',
        'polzovatelskoe-soglashenie',
        'oplata',
    ],
    'auto_sync_enabled' => true,
    'sync_time' => '02:30',
]
```

## 2. Table: `wp_crs_sync_map`

Назначение: карта соответствия remote/local объектов.

### Поля

- `id` bigint unsigned PK
- `object_type` varchar(32) — `product`, `product_cat`, `page`
- `remote_id` bigint unsigned
- `local_id` bigint unsigned
- `remote_slug` varchar(200)
- `remote_modified_gmt` datetime null
- `payload_hash` char(64)
- `last_synced_at` datetime null
- `last_operation_status` varchar(20)
- `last_error_message` text null
- `created_at` datetime
- `updated_at` datetime

### Индексы

- unique `(object_type, remote_id)`
- index `(object_type, local_id)`
- index `(remote_slug)`
- index `(last_operation_status)`

## 3. Table: `wp_crs_sync_logs`

Назначение: журнал запусков.

### Поля

- `id` bigint unsigned PK
- `run_type` varchar(20) — `manual`, `cron`, `connection_test`, `primary_regionalization`
- `started_at` datetime
- `finished_at` datetime null
- `status` varchar(20) — `success`, `partial`, `error`, `running`
- `checked_count` int unsigned
- `updated_count` int unsigned
- `created_count` int unsigned
- `skipped_count` int unsigned
- `error_count` int unsigned
- `message` text null
- `context_json` longtext null

## 4. Object meta

В MVP не делаем сложное дублирование карты в post meta / term meta.

Источник истины для соответствий — `wp_crs_sync_map`.

Дополнительно можно использовать технические метки:
- post meta `_crs_remote_id`
- term meta `_crs_remote_id`

Но только как вспомогательные.

## 5. Хэш полезной нагрузки

Рекомендация:
- сериализовать только значимые поля объекта;
- хэшировать `sha256(json_encode($normalized_payload))`.

Это уменьшает ложные обновления.

## 6. Состав normalized payload

### Product
- remote id
- title
- slug
- status
- content
- short_description
- category ids
- seo_meta_title
- seo_meta_description
- modified_gmt

### Product category
- remote id
- name
- slug
- description
- seo_meta_title
- seo_h1
- seo_meta_description
- modified_gmt

### Page
- remote id
- title
- slug
- status
- content
- excerpt
- modified_gmt

## 7. Lock option

Для защиты от параллельных запусков нужна отдельная option, например:

- `crs_sync_lock`

Структура:

```php
[
    'locked_at' => '2026-03-18 02:30:00',
    'run_type' => 'cron',
]
```

Старый lock должен уметь протухать, например через 2 часа.
