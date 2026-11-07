Модель: Sonnet 5.5

# SP-E8-24K

- V-11.5 (остаток 07.11): у Наблюдателя больше нет «Доступа к полным ответам» в таблице получателей — проверено: тест `RecipientPermissionsE824JTest` (viewer=false, editor=true, owner=true), скриншот `.local/screenshots/SP-E8-24K/permissions.png` (светлая тема, 1440) совпадает с макетом 131/096135c9 (замок + текст «Нет доступа», сноска «Доступ к полным ответам доступен владельцу, администратору и редактору»).
- Новая узкая способность `vision.measure.full_access` (owner/admin/editor) в `config/permissions.php`; `SettingsController::members()` теперь считает `canReadAnswers` по ней, а не по `vision.measure.read`.
- Новый тест `ObserverMeasureAccessE824KTest`: Наблюдатель сохраняет доступ к маршрутам замеров (`/vision/runs`), но не к «полным ответам» в настройках — проверено: PHPUnit, зелёный.
- Index.tsx: иконка замка у «Нет доступа», сноска с перечнем ролей с доступом.
- Пустое/ошибочное состояния, внешние адреса, три темы, ширины 1440/1024/390 — ранее закрыты в SP-E8-24J, проверены повторно (не менялось поведение, зелёный browser-тест того же файла).
- PHPUnit: `tests/Feature/Vision/Settings/` — 25 тестов зелёные; `tests/Feature/Vision/Measurements/` — 32 (без регрессий, не затронуты). Playwright `tests/Browser/E8-24K/permissions.spec.ts` (3) — зелёные. `check lint` (Pint), `check frontend` (typecheck/test/build) — зелёные.

## Принято по умолчанию

- Не стал убирать `viewer` из существующей `vision.measure.read` (её используют все маршруты замеров — список/прогресс/ответы/отчёты/публикации/тренды, в т.ч. сама страница `/vision/settings`); так Наблюдатель потерял бы доступ к этим экранам целиком, что ломает действующие тесты (`SettingsRolesTest` и 7 файлов `tests/Feature/Vision/Measurements/`) и расходится с остальными приёмками модуля. Вместо этого завёл отдельную способность `vision.measure.full_access` только для признака «полные ответы» в таблице получателей — это и есть разница по макету.

## Техдолг

- Нет.
