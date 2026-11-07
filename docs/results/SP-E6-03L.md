# SP-E6-03L — Policy handoff в защищённых ходах

- `FollowupFinalizer::finishProtectedApi` и `FirstTurnTextFinalizer::write` принимают goal.kind `handoff` (ранее отклонялся как не из whitelist): при handoff выставляют `conversation.mode='manager'`, `handed_off_at`; стадию/исход (`handoff`) и так считает Policy (готово в SP-E6-03C). Для FollowupFinalizer — только в режиме `bot` (не для `suggest`).
- Policy сама не менялась: off-topic-порог и негатив-стрик (D-5.6) уже вызывают `handoffGoal()`; изменение только в финализаторах, которые раньше бросали `LogicException` на этом kind.
- Проверено: явный запрос «хочу живого человека» (intent `want_human`) доходит до handoff и на первом ходе (FirstTurnTextFinalizer), и на защищённом follow-up (FollowupFinalizer/job-путь симуляции).

## Тесты
- `tests/Feature/ProtectedOperations/HandoffFinalizersE603LTest.php` — 2 теста: follow-up доходит до handoff (mode/stage/outcome/handed_off_at, идемпотентный повтор), изоляция бренда (чужой бренд — IdempotencyFailure, conversation не тронута). Прогнано: проверено.
- Новый тест в `FirstTurnStateTest.php` (`test_first_text_hands_off_conversation_on_explicit_want_human_request`) — первый ход через `FirstTurnTextFinalizer`. Не прогнан локально: роль БД слота exec-1 имеет `CREATEDB` (проверено `tinker`), а файл требует изолированную роль без этой привилегии (`createApplication()` в файле бросает `RuntimeException`) — падают и старые тесты этого файла без моих правок. Проверено через смежные production-тесты (`FirstInboundRuntimeTest`, `GreetingFirstTurnTest` — 5 тестов, зелёные), которые реально вызывают изменённый `FirstTurnTextFinalizer::write`.
- Регрессия: `ProtectedSimulationRuntimeTest` (7 тестов), `OffTopicPolicyE603CTest`+`PolicyTest` (13 тестов) — зелёные. Pint, `check-test-suite-coverage.py` — зелёные; новый файл зарегистрирован в `phpunit.xml` (ShellCore).

## Остаток
- Tool `escalate` из `allowedTools` handoff-цели не реализован в `ToolRunner` (вернёт `unavailable`, если модель его вызовет); в тестах fake-driver не вызывает tool, текст идёт прямым ответом. Полноценный обработчик — вне scope этой карточки.

## Техдолг
- Ограничение среды exec-1: роль БД слота с `CREATEDB` не проходит защитный chek в `FirstTurnStateTest`/аналогичных файлах (`DB_TEST_DATABASE`-гейт) — не даёт локально гонять этот набор в этом слоте; не связано с кодом задачи.

## Принято по умолчанию
- Повторный визит уже переданного диалога (handoff repeat) не трогает `handed_off_at` второй раз: `FollowupFinalizer` вызывается только пока `conversation.mode` ещё `bot`/`suggest`.
