# SP-E3-07G — рабочий runtime replay (реопен 08.11, расширенный scope)

## Сделано
- `FirstTurnGoal.php:52`: `isFirstTurn` `false`→`true` (единственный неверный вызов `decide()` для первого хода;
  реальный баг прода, не только эквивалентности). Карточка LeadState теперь `qualify`, как у донора.
- `ApiOpeningInput.php:70`: `[]` → `$goal->allowedTools`. Все 29 `persona.*` проходят байт-в-байт полностью
  (`test_persona_cases_open_through_the_api_opening_runtime`). Обновлён `ApiOpeningInputE503FTest` (раньше
  фиксировал этот баг как ожидаемое поведение).
- `FollowupSender.php` + `PrepareFollowups.php`: запрос «последнего агентского сообщения» (база отсчёта тишины)
  исключает собственные напоминания (`meta.goal.kind = followup_nudge`) — второй порог теперь считается от начала
  молчания, как у донора. `manual.followup_40h` совпадает с записью на всех шагах (было 60ч вместо 40ч).
- Обновлены комментарии/ассерты в `RuntimeReplayE307GTest.php` под новый статус. `phpunit.xml` уже регистрировал
  тест в предыдущей итерации — не трогал.

## Тесты
- `tests/Equivalence/RuntimeReplayE307GTest.php` — 4/4, 131 проверка.
- `GreetingFirstTurnTest`, `FirstInboundRuntimeTest` — зелёные (прод-поведение первого хода не регрессировало).
- `FollowupSendTest`, `DelayedManagerTaskE603TTest`, `FollowupPolicyTest`, `FollowupJobTest`, `FollowupFallbackE706DTest` — зелёные.
- `ApiOpeningE503FTest`, `ApiOpeningInputE503FTest` (обновлён) — зелёные.
- `check lint` (composer validate + pint) — зелёный.

## Остаток
- 9 диалоговых + `no_repeat_filled_slot`: `ЦЕЛЬ ХОДА` у нас `intro_and_ask`, у донора `ask_slot` — донорские трейсы
  предполагают уже отправленный проактивный опенинг перед первым ответом клиента, которого `FirstInboundRuntime` для
  клиент-инициированного диалога не посылает. Архитектурное отличие replay, не однострочная правка.
- `manual.force_close_14` блокируется той же причиной до периодического `summary`-вызова.

## Техдолг
- `FirstTurnStateTest.php`, `CreatePromiseTest.php` падают в слоте exec-1 с «An isolated test database is required»
  (проверка роли Postgres rolsuper/rolcreatedb) независимо от этих правок — инфраструктура слота, не код задачи.

## Принято по умолчанию
- Фильтр по `meta.goal.kind != followup_nudge` применён и в `FollowupSender`, и в `PrepareFollowups` — у них
  раздельные одинаковые запросы «последнего агентского сообщения»; правка только одного маскировалась вторым.
