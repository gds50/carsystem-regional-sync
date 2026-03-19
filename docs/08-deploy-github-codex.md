# Развёртывание через GitHub и Codex

## 1. Локально или на сервере

Создайте репозиторий и загрузите туда этот комплект.

```bash
git init
git add .
git commit -m "chore: bootstrap plugin repo"
```

## 2. GitHub

```bash
git branch -M main
git remote add origin <your_repo_url>
git push -u origin main
```

## 3. Подключение Codex

В Codex дайте стартовую задачу:

> Read CODEX.md and docs. Implement milestone 1 and milestone 2 only. Keep MVP scope strict. Commit logically with conventional commits.

## 4. Рекомендуемый цикл

Для каждого milestone:

1. Codex читает документацию.
2. Codex реализует только один этап.
3. Вы прогоняете `make lint`.
4. Вы принимаете изменения.
5. Делается commit.

## 5. Ветка работы

Рекомендуемый простой вариант:
- `main` — стабильная ветка
- `feature/milestone-x-*` — рабочие ветки

## 6. Staging

Удобный поток:
- код из GitHub попадает на staging;
- собранный ZIP ставится как обычный плагин;
- после проверки merge в main.

## 7. Что говорить Codex

Хороший шаблон задачи:

> Прочитай CODEX.md и docs/01-07. Реализуй только Milestone 4. Не добавляй новые фичи. Для всех write actions используй nonce, capability checks и проверку пользователя caradmin. Обнови документацию, если меняешь схему данных.

## 8. Когда просить Codex обновлять документы

Просите обновлять docs, если он меняет:
- структуру таблиц;
- формат настроек;
- поток синхронизации;
- стратегию логирования;
- ограничения безопасности.
