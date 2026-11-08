# SP-E3-07G — рабочий runtime replay (реопен 08.11, расширенный scope)

## Сделано
- `ApiOpeningInput.php:70`: `[]` → `$goal->allowedTools`. Все 29 `persona.*` проходят байт-в-байт полностью
  (`test_persona_cases_open_through_the_api_opening_runtime`). Обновлён `ApiOpeningInputE503FTest` (раньше
  фиксировал этот баг как ожидаемое поведение).
- `FollowupSender.php` + `PrepareFollowups.php`: запрос «последнего агентского сообщения» (база отсчёта тишины)
  исключает собственные напоминания (`meta.goal.kind = followup_nudge`) — второй порог теперь считается от начала
  молчания, как у донора. `manual.followup_40h` совпадает с записью на всех шагах (было 60ч вместо 40ч).
- `FirstTurnGoal.php:52` (`isFirstTurn` `false`→`true`) — **гипотеза проверена и отвергнута**: локально карточка
  LeadState стала показывать `qualify` как у донора, но CI (ветка 59b2cf31) показал красным
  `FirstTurnStateTest::test_first_client_message_follows_donor_inbound_goal_not_proactive_opening` и 3 смежных
  теста того же файла — они осознанно фиксируют, что первое сообщение клиента здесь следует донорской цели ПОСЛЕ
  опенинга (`ask_slot`/`answer_and_ask`), а не самому опенингу (`intro_and_ask`): опенинг в проде идёт отдельным
  механизмом (настройка приветствия, `GreetingFirstTurnTest`), не этой веткой `Policy::decide()`. Правка откатана
  на `false`, комментарий в коде указывает на этот пин. Совпадение `qualify` в DIALOGUE_CASES было случайным — эти
  донорские трейсы сами предполагают уже прошедший опенинг.
- Обновлены комментарии/ассерты в `RuntimeReplayE307GTest.php` под итоговый статус.

## Тесты
- `tests/Equivalence/RuntimeReplayE307GTest.php` — 4/4, 131 проверка (с `isFirstTurn=false`, как и было).
- `GreetingFirstTurnTest`, `FirstInboundRuntimeTest` — зелёные.
- `FollowupSendTest`, `DelayedManagerTaskE603TTest`, `FollowupPolicyTest`, `FollowupJobTest`, `FollowupFallbackE706DTest` — зелёные.
- `ApiOpeningE503FTest`, `ApiOpeningInputE503FTest` (обновлён) — зелёные.
- `check lint` (composer validate + pint) — зелёный.
- CI ветки (run 1, 59b2cf31): упал на `FirstTurnStateTest` из-за гипотезы выше — гипотеза отвергнута и откатана
  в следующем коммите этой же ветки.

## Остаток
- 9 диалоговых + `no_repeat_filled_slot`: `ЦЕЛЬ ХОДА` у нас `intro_and_ask`, у донора `ask_slot` — донорские трейсы
  предполагают уже отправленный проактивный опенинг перед первым ответом клиента, которого `FirstInboundRuntime` для
  клиент-инициированного диалога не посылает. Архитектурное отличие replay (нужна симуляция опенинга в тесте или
  в коде), не однострочная правка.
- `manual.force_close_14` блокируется той же причиной до периодического `summary`-вызова.

## Техдолг
- `FirstTurnStateTest.php`, `CreatePromiseTest.php` падают в слоте exec-1 с «An isolated test database is required»
  (проверка роли Postgres rolsuper/rolcreatedb) независимо от этих правок — инфраструктура слота, не код задачи;
  проверено реальным CI, где эти файлы запускаются нормально.

## Принято по умолчанию
- Фильтр по `meta.goal.kind != followup_nudge` применён и в `FollowupSender`, и в `PrepareFollowups` — у них
  раздельные одинаковые запросы «последнего агентского сообщения»; правка только одного маскировалась вторым.
