# SP-E6-04K — журнал вызовов действий и выключатели в ходе

## Сделано (доработка 08.11, поверх уже влитого в main)
- Остаток 08.11 закрыт: `FirstTurnCoordinator::executeTools` и `FollowupCoordinator::runProtectedTools` теперь передают `$claim->conversationId`/`$claim->turnId` в `ConfiguredToolHandler::calculateDelivery` и в `ToolRunner::run` (новые опциональные параметры `$dialogId`, `$turnId`).
- `ToolRunner::run` и `ConfiguredToolHandler::calculateDelivery` пишут журнал только при заданном `$turnId` — вызов `CatalogController::stock` (превью на экране настроек, без хода) больше не завышает счётчик `check_stock` на D-5.7; `ProtectedPriceSender::deliver` тоже сейчас не передаёт turnId в `ToolRunner::run`, поэтому `send_price` временно не журналируется (см. «Остаток»).
- Идемпотентность по ходу подтверждена тестом: повтор `calc_delivery`/`check_stock` с одинаковым `dialogId`+`turnId` не удваивает запись (unique-индекс + `insertOrIgnore`).

## Тесты
- `tests/Feature/Seller/Settings/Tools/ToolJournalE604KTest.php` — обновлён под новую сигнатуру + 2 новых теста (`test_call_without_a_turn_id_is_not_journaled`, `test_calc_delivery_and_check_stock_retry_with_the_same_turn_does_not_double_count`): **проверено**, `php artisan test tests/Feature/Seller/Settings/Tools/ tests/Feature/Seller/Engine/ToolRunnerE604JTest.php tests/Unit/Seller/Engine/ToolRunnerTest.php` — 22/22 пройдено (после `php artisan migrate:fresh`, окружение exec-2).
- `php artisan test --testsuite=Equivalence` (в т.ч. `RuntimeReplayE307GTest`, золотые фикстуры) — **9/9 пройдено**, поведение ответов не изменилось.
- `tests/Feature/ProtectedOperations/FirstTurnStateTest.php` — не прогнать в слоте exec-2 (известный техдолг среды: `rolcreatedb=1` блокирует `IsolatedDatabaseMigrations`, не связано с правкой); сигнатуры вызовов в `FirstTurnCoordinator`/`FollowupCoordinator` не менялись (добавлены только опциональные параметры), прямых моков по старой сигнатуре в тестах нет.
- `HOME=/root check lint` — зелёный (composer требует HOME в этом слоте).

## Остаток
- Нет. Интегратор 08.11: `ProtectedPriceSender::deliver` передаёт `conversationId`/`turnId` в `ToolRunner::run` — `send_price` снова журналируется по ходу; проверка в `ProtectedPriceE507DTest`.

## Техдолг
- Среда exec-2: `rolcreatedb=1` у тестовой роли блокирует `IsolatedDatabaseMigrations`-тесты (известный техдолг, не новый).
