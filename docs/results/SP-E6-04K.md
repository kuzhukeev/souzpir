# SP-E6-04K — журнал вызовов действий и выключатели в ходе

## Сделано
- Таблица `seller_tool_call_journal` (append-only): organization_id, brand_id, dialog_id, turn_id (для идемпотентности), tool, status, created_at; unique(org,brand,dialog,turn,tool), FK на brands/conversations.
- `ToolCallJournal` (app/Seller/Engine/Tools/): запись + подсчёт за окно; `insertOrIgnore` — повтор хода не удваивает счётчик.
- `ToolRunner::run`: пишет журнал по каждому вызову, где известен TenantContext (6 действий, не перехваченных раньше в координаторе: set_followup, create_promise, mark_do_not_disturb, check_stock, log_unknown_question, send_requisites); добавлена проверка тумблера для check_stock/send_price/set_followup — статус `disabled`, ход не прерывается.
- `ConfiguredToolHandler::calculateDelivery`: внешний контракт (возврат вызывающему) не менял — остаётся `unavailable` (сохраняет существующие тесты вне scope, `DeliveryE604ETest`/`ToolSettingsTest`); в журнал для этого случая пишет более точный статус `disabled`, чтобы экран отличал выключенный тумблер от отсутствия конфигурации.
- `EscalateToManager::run`: пишет журнал внутри уже существующей идемпотентной обёртки по ключу turn+tool — повтор хода не создаёт вторую запись (проверено тестом).
- `ToolSettingsController::tools()`: «Вызовы за 30 дней» теперь считаются по журналу для всех 9 действий; для create_promise/escalate добавлена сумма со старыми источниками (audit_logs/seller_tasks) за период до первой записи журнала бренда — совместимость с данными до миграции.
- Tools.tsx менять не потребовалось: таблица уже рендерит `callCounts` по всем ключам settings.tools (сделано в SP-E6-04G).

## Тесты
- `tests/Feature/Seller/Settings/Tools/ToolJournalE604KTest.php` (новый, 4 теста): запись+выключатель, бренд-изоляция calc_delivery, идемпотентность escalate по ходу, окно 30 дней — **пройдено**.
- Существующие тесты вне scope (`DeliveryE604ETest.php`, `ToolSettingsTest.php`, `ToolCallCountsE604HTest.php`) не трогал — вернул их к исходному виду, когда `task.py submit` отклонил правку чужих файлов; данные журнала для 7 «новых» действий добавляются в `callCounts` только когда есть хотя бы один вызов (count>0), create_promise/escalate остаются всегда присутствующими ключами, как раньше.
- `tests/Feature/Seller/Settings/Tools/`, `tests/Feature/Seller/Engine/ToolRunnerE604JTest.php`, `tests/Unit/Seller/Engine/ToolRunnerTest.php` — **пройдено** (кроме известного техдолга среды exec-2: `rolcreatedb=1` блокирует `IsolatedDatabaseMigrations`, не из-за правок).
- `--testsuite=Equivalence` (включая `RuntimeReplayE307GTest`, золотые фикстуры с escalate) — **9/9 пройдено**, без изменения поведения ответов.
- Новый `tests/Browser/E6-04K/tools.spec.ts`: значения у всех 9 действий + выключенное действие на 1440; без overflow на 1440/1024/390 — **пройдено**. Скриншот `.local/screenshots/SP-E6-04K/tools-1440.png` сверен с **mockups D-5.7-main.webp**.
- `check lint`, `check frontend` (eslint/tsc/vitest/build) — зелёные.

## Остаток
- Полная проводка dialog_id/turn_id для calc_delivery и остальных 6 действий через `ToolRunner`/`ConfiguredToolHandler` требует правки точки вызова в `app/ProtectedOperations/FirstTurnCoordinator.php` (передать `$claim->conversationId`/`$claim->turnId` вместо одного `TenantContext`) — файл вне scope карточки и сейчас в работе другой задачи (SP-E3-07G). До этой правки журнал для этих действий пишет dialog_id/turn_id как null (счётчики на экране корректны, т.к. считаются по organization/brand, а не по диалогу); идемпотентность по ходу для них пока не гарантирована.
- `send_price` в реальном ходе выполняется через `App\Seller\Channels\Email\ProtectedPriceSender` (вне scope) — туда журнал не подключён, поэтому в бою счётчик `send_price` останется «нет данных», пока интегратор не добавит туда запись отдельной строкой.

## Принято по умолчанию
- Карточка требует статус `disabled` у выключенного тумблера; `task.py submit` заблокировал правку `DeliveryE604ETest.php`/`ToolSettingsTest.php` (вне scope) под новый статус `calc_delivery`. Оставил внешний возврат `ConfiguredToolHandler` как есть (`unavailable`), `disabled` пишется только в журнал — отличить причину можно там же. Для `check_stock`/`send_price`/`set_followup` в `ToolRunner` конфликтующих тестов не было — там возвращается `disabled` напрямую, как в карточке.

## Техдолг
- См. «Остаток» выше — обе строки перенести в `docs/tech-debt.md`.
