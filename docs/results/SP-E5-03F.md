# SP-E5-03F — защищённые инструменты и регенерация последующих API-ходов

- Последующие API/сессионные ходы продавца (bot/suggest/симуляция) теперь предлагают responder'у разрешённые tools на первом раунде; при ответе `tool_calls` сервер один раз выполняет их (`calc_delivery`, `send_price`, прочее — через существующий `ToolRunner`/`ConfiguredToolHandler`/`ProtectedPriceSender`) и передаёт результаты в продолжение раунда.
- Если детерминированный Guard или модельный Guard отклоняет ответ, ход перегенерируется с замечаниями Guard (до 3 раундов суммарно), прежде чем безопасно завершиться без отправки. Раунды различаются отдельным идемпотентным ключом (`...-{round}`); финализация подтверждает тот же раунд/toolResults/guardIssues, что и принятый ответ.
- Оркестрация вынесена в `FollowupCoordinator::orchestrateTurn`/`prepareFinalRound` и используется сессионным (`run`), job-симуляцией (`runJob`) и токен-API (`ApiFollowupRuntime::turn`) путями; атомарность финализации с доставкой в токен-API сохранена (`prepareFinalRound` вне транзакции, `finishProtectedApi`+доставка в одной).

## Тесты
- PHPUnit (fake-only, PostgreSQL): `FollowupHttpTest` (3, включая новый `test_followup_executes_an_offered_tool_round_before_answering`), `ProtectedSimulationRuntimeTest` (7, включая новый `test_model_guard_regenerates_and_recovers_after_a_rejection`, и обновлённый `test_model_guard_must_approve_protected_followup` — теперь 3 попытки Guard перед отказом), `SimulationBridgeE306DTest` (2), `ApiOpeningE503FTest` (2), `ApiTokenRuntimeE503CTest` (2), смежные `Api*Test` (8) — все зелёные, 24 теста/324 проверки.
- Pint: прошёл.

## Остаток
- Нет — оба пункта остатка карточки (инструменты и регенерация) реализованы.

## Техдолг
- Регенерация по замечаниям Guard не меняет сам текст детерминированно (fake-модель может вернуть тот же текст); для прототипа это ожидаемо, граница — fake-fixtures.

## Принято по умолчанию
- Бюджет попыток — 3 раунда суммарно (включая исходный), как в первом ходе (FirstTurnCoordinator); исчерпание — безопасный отказ (503), без отправки.
