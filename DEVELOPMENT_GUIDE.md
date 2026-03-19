# Руководство по разработке

## Как работать с этим набором

Этот комплект нужен не как финальный плагин, а как стартовая площадка для разработки через Codex с git-синхронизацией.

## Рекомендуемый поток

### 1. Инициализация репозитория

```bash
git init
git add .
git commit -m "chore: bootstrap regional sync kit"
```

### 2. Первый цикл с Codex

Дайте Codex задачу в таком стиле:

> Прочитай CODEX.md и docs/*. Реализуй milestone 1: bootstrap плагина, settings page shell, secure option storage, connection test button. Не выходи за рамки MVP.

### 3. Рабочие milestone

1. bootstrap и структура
2. settings storage
3. connection test
4. dictionary parser
5. primary regionalization
6. mapping table
7. logging
8. products sync
9. categories sync
10. pages sync
11. cron orchestration
12. QA и hardening

### 4. После каждого milestone

```bash
make lint
make package
git add .
git commit -m "feat(scope): short description"
```

## Обязательные принципы

- не расширять scope без обновления ТЗ;
- не добавлять публичные AJAX / REST endpoint без сильной необходимости;
- всё важное состояние хранить в WordPress options / custom tables;
- любые чувствительные значения не показывать в логах;
- все действия запускаются только из админки или cron.

## Что Codex должен сделать на раннем этапе

Сначала довести до рабочего состояния:

- активацию / деактивацию плагина;
- регистрацию настроек;
- доступ только для `caradmin`;
- тест соединения;
- экран логов;
- безопасную основу cron.

Потом уже реализовывать объектную синхронизацию.

## Как проверять результат

Минимум для каждого завершённого этапа:

- нет PHP syntax errors;
- нет fatal errors на activation;
- настройки сохраняются;
- кнопки запуска защищены nonce;
- обычный админ, не `caradmin`, не видит плагин;
- логика работает на staging-копии.
