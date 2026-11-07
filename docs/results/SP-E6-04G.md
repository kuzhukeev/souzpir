# SP-E6-04G — инструменты полного защищённого хода

- Остаток SP-E6-04E/SP-E6-03L: tool `escalate` из `allowedTools` handoff-цели больше не возвращает `unavailable`. Новый `App\Seller\Engine\Tools\EscalateToManager` (идемпотентно, по образцу `ProtectedPriceSender`) создаёт `seller_tasks` (`type=handoff`, `priority=urgent`) и шлёт уже существующее уведомление менеджерам `NotifyDialogEvent` (`seller.conversation.handed_off`, тот же путь, что у ручной передачи диалога) — фейковый, только внутрикабинетный канал, без внешних вызовов.
- Подключено в `FirstTurnCoordinator::executeTools` отдельным случаем `match`, рядом с `calc_delivery`/`send_price`; `ToolRunner` не трогал (он декларативно side-effect-free dispatcher, остаётся таким).
- Права и бренд: `EscalateToManager` отбрасывает запрос, если `tenant` не совпадает с брендом/организацией хода (как у `ProtectedPriceSender`); идемпотентность по ключу `responder:tool_id` через `PostgresTransactionalIdempotency`.

## Тесты
- `tests/Feature/ProtectedOperations/FirstTurnStateTest.php::test_coordinator_escalates_through_fake_notification_sp_e6_04g` — полный защищённый ход (`FirstTurnCoordinator::run`) с реальным tool-call `escalate`: диалог переходит в `mode=manager`/`stage=handoff`, создаётся `seller_tasks` (`handoff`), пушится `NotifyDialogEvent` с нужными полями; повторный вызов хода не дублирует задачу/уведомление.
- Не прогнан локально: файл защищён самопроверкой изолированной тестовой БД (`rolcreatedb`/`rolsuper` должны быть выключены), у роли БД слота exec-19 `rolcreatedb=1` — тот же известный техдолг среды, что и в отчёте SP-E6-03L. Проверено: `php -l`, Pint (`composer lint`, зелёный), `check-test-suite-coverage.py` (зелёный, новый тест — в уже зарегистрированном файле).
- Регрессия: `tests/Unit/Seller/Engine/ToolRunnerTest.php`, `tests/Feature/Seller/Engine/ToolRunnerE604JTest.php`, `tests/Feature/Seller/Engine/ClosedTopicNextActionE410LTest.php`, `tests/Unit/Seller/Engine/Tool{Golden,Catalog,Arguments}Test.php`, `tests/Unit/Seller/Settings/Tools/ToolSettingsTest.php` — все зелёные (16+4 теста), `ToolRunner.php` не менялся.

## Остаток
- «Подключить реквизиты» (send_requisites) и автонотификацию `notify_manager`/`manager_notified` (крупный заказ) требуют правок в `FirstTurnTextFinalizer.php` — файл занят SP-E7-04M/SP-E5-04N, вне scope этой карточки.
- Tool `create_promise` (готовый `App\Seller\Engine\CreatePromise`) всё ещё не подключён к `FirstTurnCoordinator::executeTools` — отдельное действие, не упомянутое в явном остатке этой карточки; не трогал, чтобы не расширять scope.

## Техдолг
- Среда exec-19: роль БД слота с `rolcreatedb=1` не проходит защитный гейт `FirstTurnStateTest`/аналогичных файлов — не даёт локально гонять этот набор (как и в SP-E6-03L).

## Принято по умолчанию
- Side-effect для escalate реализован отдельным классом `app/Seller/Engine/Tools/EscalateToManager.php`, вызываемым из координатора, а не телом `ToolRunner::run` — сохраняет его собственный контракт «side-effect-free dispatcher» (докблок класса) и тот же паттерн, что уже используют `calc_delivery`/`send_price`.
