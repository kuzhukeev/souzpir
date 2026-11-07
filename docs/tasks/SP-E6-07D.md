# SP-E6-07D · Лимиты и режимы каналов

**Зачем:** завершить экран по принятому результату.
**Часть чего:** остаток SP-E6-07C, docs/results/SP-E6-07C.md.
**Зависимости:** SP-E6-07C.

## Приёмка
- Реализовать запасную фразу и автоматическую передачу при исчерпании, применение переопределения режима каналом, сверить состояния загрузки и ошибки с макетом; согласовать runtime-контракт.
- Проверить права, изоляцию брендов, ошибки и состояния; незавершённое указать в «Остаток». Без живых и платных вызовов.

Scope: `app/Seller/Settings/Limits/`, `app/Seller/Runtime/BrandRuntimeSchedule.php`, `app/Seller/Runtime/StageTransitions.php`, `resources/js/pages/Seller/Settings/Limits/`, `resources/js/pages/Seller/Settings/Schedule/`, `tests/Feature/Seller/Settings/Limits/`, `tests/Feature/Seller/Settings/Schedule/`, `tests/Browser/E6-07D/`, `docs/results/SP-E6-07D.md` (сужено 07.11.2026).

## Остаток 07.11.2026 21:00 (после слияния частичной сдачи)
- Влито: `BrandRuntimeSchedule::mode()/available()` с опциональным каналом (читают `settings.channel_overrides`), `fallbackPhrase()`, честные тексты экранов D-11.1/D-11.3, тесты и Playwright E6-07D. Осталось: 1) сохранение `channel_overrides` из формы «Режим работы» — `app/Seller/Settings/Schedule/ScheduleController.php` (поля переопределения режима по каналам telegram/widget/api в `resources/js/pages/Seller/Settings/Schedule/Index.tsx`, валидация, feature-тест); 2) передача канала в `mode()/available()` и доставка `fallbackPhrase()` клиенту при хендоффе по лимиту — `app/Seller/Runtime/FirstInboundRuntime.php`, `TokenInboundRuntime.php`, `WidgetInboundRuntime.php`, `ApiFollowupRuntime.php` (feature-тесты в `tests/Feature/Seller/Settings/Schedule/`).
- Scope дополнен: `app/Seller/Settings/Schedule/ScheduleController.php`, `app/Seller/Runtime/FirstInboundRuntime.php`, `app/Seller/Runtime/TokenInboundRuntime.php`, `app/Seller/Runtime/WidgetInboundRuntime.php`, `app/Seller/Runtime/ApiFollowupRuntime.php`, `tests/Browser/E6-07C/`.

## Уточнение 07.11.2026 22:30
- `app/Seller/Runtime/WidgetInboundRuntime.php` исключён из scope (занят SP-L1-09, в живом режиме виджет всегда подавляет ход): переопределение режима для канала widget — пунктом «Нужно от интегратора», не делать.
- Добавлено из SP-E6-06E: в `FirstInboundRuntime::suppressManagerTurn` при подавлении хода по расписанию (вне часов) и по лимиту клиенту возвращается `BrandRuntimeSchedule::fallbackPhrase()` как ответ бота (reply в outcome подавленного хода); feature-тест в `tests/Feature/Seller/Settings/Schedule/` или `tests/Feature/Seller/Runtime/`. Подпись D-11.1 «автоматическая отправка фразы вне часов пока не подключена» заменить на фактическое поведение.

Scope: `app/Seller/Settings/Limits/`, `app/Seller/Settings/Schedule/ScheduleController.php`, `app/Seller/Runtime/BrandRuntimeSchedule.php`, `app/Seller/Runtime/StageTransitions.php`, `app/Seller/Runtime/FirstInboundRuntime.php`, `app/Seller/Runtime/TokenInboundRuntime.php`, `app/Seller/Runtime/ApiFollowupRuntime.php`, `resources/js/pages/Seller/Settings/Limits/`, `resources/js/pages/Seller/Settings/Schedule/`, `tests/Feature/Seller/Settings/Limits/`, `tests/Feature/Seller/Settings/Schedule/`, `tests/Feature/Seller/Runtime/`, `tests/Browser/E6-07D/`, `tests/Browser/E6-07C/`, `docs/results/SP-E6-07D.md`.
