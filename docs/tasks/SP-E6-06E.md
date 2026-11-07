# SP-E6-06E · Завершение расписания и распределения

**Зачем:** завершить экран по принятому результату.
**Часть чего:** остаток SP-E6-06D, docs/results/SP-E6-06D.md.
**Зависимости:** SP-E6-06D.

## Приёмка
- Завершить распределение по городу, каналу и источнику, рабочие часы и каналы менеджеров, таймер уведомления, отправку фразы вне часов и защищённую передачу; сверить макет и состояния загрузки/ошибки. Без живых отправок.
- Проверить права, изоляцию брендов, ошибки и состояния; незавершённое записать в «Остаток».

Scope: `app/Seller/Settings/Schedule/`, `app/Seller/Conversations/`, `resources/js/pages/Seller/Settings/Schedule/`, `tests/Feature/Seller/Settings/Schedule/`, `tests/Feature/Seller/Conversations/`, `tests/Browser/E6-06E/`, `docs/results/SP-E6-06E.md` (сужено 07.11.2026).

## Остаток 07.11.2026 21:10 (после частичной сдачи)
- `app/Seller/Runtime/BrandRuntimeSchedule.php::assignWaiting`: передавать канал диалога, `now` и таймзону в `AssignmentCandidates::select`, чтобы рабочие часы и каналы менеджеров реально ограничивали назначение; feature-тест.
- Отправка фразы вне часов и уведомление по таймеру «никто не взял» (`notify_minutes`/`notify_target`) через fake-доставку и общую очередь уведомлений; таймер — в `app/Seller/Settings/Tools/MonitorHandoffs.php` (не дублировать).
- `tests/Browser/E6-06D/schedule.spec.ts` красный после смены UI (старые заглушки «недоступны», select «По городу») — обновить локаторы по образцу `tests/Browser/E6-06E/schedule.spec.ts`, проверки не ослаблять.

Дополнительный scope: `app/Seller/Runtime/BrandRuntimeSchedule.php`, `app/Seller/Settings/Tools/MonitorHandoffs.php`, `tests/Browser/E6-06D/`, `tests/Feature/Seller/Runtime/`.

## Остаток 07.11.2026 22:10 (после слияния wiring и таймера)
- Влито: рабочие часы/каналы менеджеров ограничивают `assignWaiting`; таймер «никто не взял» через `MonitorHandoffs::monitorUnassigned()`; browser-тест E6-06D обновлён.
- Осталось: доставка фразы вне часов клиенту — в `FirstInboundRuntime::suppressManagerTurn` при подавлении хода по расписанию/лимиту вернуть клиенту `BrandRuntimeSchedule::fallbackPhrase()` (текст в outcome/reply подавленного хода, как сообщение бота в диалоге); без живых отправок. Feature-тест в `tests/Feature/Seller/Runtime/`. Подпись на D-11.1 «автоматическая отправка фразы вне часов пока не подключена» заменить на фактическое поведение.
- `WidgetInboundRuntime.php` занят SP-L1-09 — не трогать.

Scope остатка: `app/Seller/Runtime/FirstInboundRuntime.php`, `app/Seller/Runtime/BrandRuntimeSchedule.php`, `tests/Feature/Seller/Runtime/`, `resources/js/pages/Seller/Settings/Schedule/`, `tests/Browser/E6-06E/`, `docs/results/SP-E6-06E.md`.
