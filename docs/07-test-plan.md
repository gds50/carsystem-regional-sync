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
- exclusions очищаются
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
- снятая с публикации скрывается

### Products
- новый товар создаётся
- изменённый товар обновляется
- категории привязываются
- SEO регионализируется после обновления

### Pages
- новая страница создаётся
- изменённая страница обновляется
- exclusions работают
- регионализация не применяется

## 7. Lock tests

- второй запуск при активном lock не стартует
- протухший lock снимается

## 8. Logging tests

- создаётся запись запуска
- counters заполняются
- ошибки сохраняются без sensitive data

## 9. Manual run tests

- `Запустить сейчас` работает только по nonce
- `Первичная регионализация` работает только по nonce
