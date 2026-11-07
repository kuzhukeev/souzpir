# SP-E4-04F · Темы: неизвестные вопросы в runtime

Остаток закрыт по аудиту 08.11: D-4.6 «Частота за 30 дней» и «Ответы „не знаю“» теперь учитывают и followup-ход, не только первый.

## Что сделано
- `FollowupFinalizer::finishProtectedApi()` теперь берёт `client_intent` из extraction последующего хода (6-й элемент `ApiFollowupInput::responderLocked()`, ранее отбрасывался) и:
  - пишет ключ `intent` в meta ответа агента (рядом с уже существующими `fake/suggested/goal_kind/guard`);
  - обновляет meta клиентского входящего сообщения тем же `intent`, по образцу `FirstTurnTextFinalizer`.
- Изменение — только добавление ключа meta; текст ответа, guard-решения и `CreatePromise`/`seller_tasks`-логика не трогались.
- `TopicsController` не менялся: группировка по `meta.intent` в нём уже была ролeнезависимой, после фикса followup-сообщения сами попадают в выборку.

## Тесты
- Новый `UnknownQuestionManagerTaskTest::test_followup_turn_counts_in_topics_frequency_and_unknown_rate_for_own_brand_only`: followup с unknown_fact → `frequencies.unclear=1`, `unknownRates.unclear=100`, пример вопроса в `frequentQuestions`, чужой бренд (`viewer` без доступа к brandA) видит пустые `frequencies`.
- Регрессия: `tests/Feature/Seller/Knowledge/Topics/*` (8 passed), `tests/Feature/Seller/Channels/Api/ApiOpeningE503FTest` (2 passed), `tests/Feature/SellerRuntime/Turns/FollowupHttpTest` + `TurnHttpTest` (4 passed), `AssignmentE606CTest`, `QuotaRuntimeE113Test`, `QuotaTransitionE704FTest`, `UnknownQuestionTaskE404FTest`, `FakeDeliveryTest`, `ApiTokenRuntimeE503CTest` — все зелёные (всего 15 тестов, 167 assertions по смежным наборам).
- `check lint` (pint + composer validate): passed.

## Остаток
Нет — пункт аудита 08.11 по D-4.6/D-4.7 закрыт полностью в рамках сузженного scope (FollowupFinalizer + TopicsController + tests/Feature/Seller/Knowledge/Topics).

## Техдолг
Нет новых рисков сверх уже известных по модулю.

## Принято по умолчанию
Не менял `TopicsController` сверх необходимого: его группировка по `meta.intent` уже была общей для ролей/ходов, фикс полностью решается на стороне финализатора — отдельный «учёт в TopicsController» из формулировки аудита оказался следствием фикса меты, а не отдельной правкой контроллера.
