Модель: Sonnet 5.5

# SP-E8-24K

- V-11.5 (остаток 07.11): у Наблюдателя больше нет «Доступа к полным ответам» в таблице получателей — проверено: тест `RecipientPermissionsE824JTest` (viewer=false, editor=true, owner=true), скриншот `.local/screenshots/SP-E8-24K/permissions.png` (светлая тема, 1440) совпадает с макетом 131/096135c9 (замок + текст «Нет доступа», сноска «Доступ к полным ответам доступен владельцу, администратору и редактору»).
- Новая узкая способность `vision.measure.full_access` (owner/admin/editor) в `config/permissions.php`; `SettingsController::members()` теперь считает `canReadAnswers` по ней, а не по `vision.measure.read`.
- Index.tsx: иконка замка у «Нет доступа», сноска с перечнем ролей с доступом.
- Пустое/ошибочное состояния, внешние адреса, три темы, ширины 1440/1024/390 — ранее закрыты в SP-E8-24J, проверены повторно (не менялось поведение, зелёный browser-тест того же файла).

## Остаток 07.11 доработан (reopen 08.11 00:05)

- `vision.measure.full_access` применён на самих маршрутах полных ответов, а не только на подписи в таблице: `AnswersController::data` (`/vision/runs/{id}/answers/data`) отдаёт 403 без этой способности; `MeasurementsController::data` (`/vision/runs/data?view=details`) скрывает `answer_text` (null) в детальной истории. Наблюдатель сохраняет доступ к списку и прогрессу замеров.
- Тест `ObserverMeasureAccessE824KTest` расширен: проверяет 403 на `/answers/data` и `null` в `answers.0.answer_text` для viewer, видимый текст для editor — проверено: PHPUnit, зелёный.
- PHPUnit: `tests/Feature/Vision/Settings/` — 26 тестов зелёные; `tests/Feature/Vision/Measurements/` — 57 зелёных, 5 skipped (race-группа, не блокер). `check lint` — зелёный. Frontend не менялся (стрипается на бэкенде, существующие `answer.answer_text &&` условия в Index.tsx и Answers.tsx уже скрывают пустой/403 ответ) — `check frontend` не запускался отдельно, т.к. .tsx-файлы не трогал.

## Принято по умолчанию

- Не стал убирать `viewer` из существующей `vision.measure.read` (её используют все маршруты замеров — список/прогресс/ответы/отчёты/публикации/тренды). Полные ответы закрыты точечно через `vision.measure.full_access` на уровне данных (403 на отдельном экране ответов, `null` в детальной истории), без изменения доступа к самим маршрутам/страницам.

## Техдолг

- Нет.
