# Архитектура MVP

## 1. Архитектурный подход

Для первой версии используется простой модульный плагин без лишней распределённости.

### Слои

1. **Bootstrap Layer**
   - инициализация констант
   - загрузка классов
   - activation / deactivation hooks

2. **Admin Layer**
   - меню
   - страницы настроек
   - формы
   - nonce и capability checks
   - кнопки ручного запуска

3. **Application Layer**
   - orchestration сервисы
   - primary regionalization runner
   - full sync runner
   - connection test runner

4. **Domain Layer**
   - dictionary parser
   - regionalization rules
   - exclusions rules
   - sync decision logic
   - payload hashing

5. **Infrastructure Layer**
   - REST API client
   - options repository
   - mapping repository
   - logs repository
   - WP cron scheduler
   - WordPress / WooCommerce persistence adapters

## 2. Принцип минимальной сложности

Для MVP не нужен event bus, CQRS и прочая перегрузка.

Нужны:
- один плагин;
- несколько сервисных классов;
- две custom tables;
- один settings option;
- один cron hook.

## 3. Хранилища

### 3.1 Options API
Используем для конфигурации:
- URL основного сайта
- API login
- application password
- region / city / area
- replacement dictionary
- partner contacts
- exclusions
- auto sync enabled
- sync time

### 3.2 Custom table: mapping
Нужна для связи remote/local объектов.

### 3.3 Custom table: logs
Нужна для истории запусков и ошибок.

## 4. Потоки данных

### 4.1 Первичная регионализация

1. `caradmin` запускает вручную.
2. Сервис перебирает локальные товары и категории.
3. Для каждого объекта читает разрешённые SEO-поля.
4. Применяет словарь замен.
5. Сохраняет только изменённые значения.
6. Пишет лог.

### 4.2 Ежедневная синхронизация

1. Cron или ручной запуск вызывает orchestrator.
2. Orchestrator проверяет lock.
3. Загружает remote categories.
4. Загружает remote products.
5. Загружает remote pages.
6. Для каждой сущности:
   - проверяет exclusions;
   - сравнивает remote modified/hash с локальной картой;
   - создаёт или обновляет локальный объект;
   - при товарах и категориях запускает регионализацию SEO-полей.
7. Пишет итоговый лог.
8. Снимает lock.

### 4.3 Требования к безопасному выполнению больших run

Для сценариев с 300+ страницами/товарами/категориями:
- обработка идёт батчами через пагинацию, без загрузки всех сущностей в память;
- run может завершиться `partial`, если часть объектов не обновилась;
- `partial` не должен блокировать следующие run;
- следующий run повторно пытается обработать объекты с ошибкой (через `last_operation_status` в mapping);
- ручной запуск и cron используют один и тот же orchestration-путь и одинаковые проверки безопасности.

## 5. Порядок синхронизации

Рекомендуемый порядок:

1. категории
2. товары
3. страницы

Причина: товары зависят от уже существующих категорий.

## 6. Идентификация изменений

Для каждого remote объекта сохраняем:
- `remote_modified_gmt`
- `payload_hash`

Обновление нужно, если:
- объекта ещё нет в mapping table;
- изменился `remote_modified_gmt`;
- изменился `payload_hash`;
- локальный объект отсутствует;
- предыдущая операция завершилась ошибкой.

## 7. Безопасность архитектуры

- нет публичных REST routes;
- нет публичных AJAX handlers;
- только admin actions и cron;
- ручные действия только после `current_user_can()` + username check + nonce;
- секреты не пишем в логи;
- application password маскируем в UI.

## 8. Упрощения MVP

Сознательно упрощаем:
- без diff-preview;
- без batch queue manager (но с безопасной пагинацией и ограничениями run);
- без async workers;
- без media sync как отдельного домена;
- без source-side plugin.

## 9. Расширение после MVP

Архитектура позволяет позже добавить:
- preview изменений;
- дополнительные SEO поля;
- partner placeholders;
- source-side companion plugin;
- более детальную историю операций;
- WP-CLI команды.
