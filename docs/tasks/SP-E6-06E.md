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

## Остаток 07.11.2026 22:10
- Доставка фразы вне часов клиенту (`FirstInboundRuntime::suppressManagerTurn` → `BrandRuntimeSchedule::fallbackPhrase()`, подпись D-11.1) передана в SP-E6-07D, где та же правка нужна для хендоффа по лимиту. Карточка закрыта.

## Остаток 07.11.2026 (аудит экранов, интегратор)
- D-11.1: часовой пояс — выбор из списка (select в стиле кабинета), а не текстовое поле.
- D-11.2: правила распределения по каналу и по источнику обращения (убрать «появятся позже»); «По городу клиента» включить, если поле города клиента уже есть в данных клиента, иначе оставить недоступным с пояснением и записать в остаток.
- Scope как в плане taskdesk: `app/Seller/Settings/Schedule/`, `app/Seller/Conversations/`, `resources/js/pages/Seller/Settings/Schedule/`, `tests/Feature/Seller/Settings/Schedule/`, `tests/Feature/Seller/Conversations/`, `tests/Browser/E6-06E/`, `docs/results/SP-E6-06E.md`, `app/Seller/Runtime/BrandRuntimeSchedule.php`, `app/Seller/Settings/Tools/MonitorHandoffs.php`, `tests/Browser/E6-06D/`, `tests/Feature/Seller/Runtime/`.



## Остаток 08.11.2026 (планировщик, проверено по коду main)
- D-11.1/D-11.2: `WidgetInboundRuntime` не обращается к `BrandRuntimeSchedule` — виджет (канал живого пилота) отвечает ботом вне рабочих часов и не отправляет фразу вне часов; подключить расписание так же, как в `FirstInboundRuntime` (mode/assignWaiting/fallbackPhrase), канал `widget`.
- `TokenInboundRuntime` и `ApiFollowupRuntime` переводят диалог менеджеру по расписанию, но не отправляют клиенту `fallbackPhrase()` — отправить фразу вне часов/лимита по образцу `FirstInboundRuntime::suppressManagerTurn` (один раз на переход, без дублей при повторе).
- Проверка: `tests/Feature/Seller/Runtime/OutOfHoursPhraseE606ETest.php` — виджет, token и API вне часов: фраза сохранена как сообщение бота, режим manager; в рабочие часы поведение прежнее; изоляция бренда. Только fake AI и fake доставка; существующие тесты Runtime зелёные.

Scope: `app/Seller/Runtime/WidgetInboundRuntime.php`, `app/Seller/Runtime/TokenInboundRuntime.php`, `app/Seller/Runtime/ApiFollowupRuntime.php`, `tests/Feature/Seller/Runtime/OutOfHoursPhraseE606ETest.php`, `docs/results/SP-E6-06E.md`.
