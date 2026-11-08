# SP-E3-07G — рабочий runtime replay (реопен 08.11, раунд 6)

## Сделано (это и прежние раунды, уже в main)
- `ApiOpeningInput.php:70`: `[]` → `$goal->allowedTools`. Все 29 `persona.*` проходят байт-в-байт
  (`test_persona_cases_open_through_the_api_opening_runtime`); пин в `ApiOpeningInputE503FTest.php`
  обновлён под новое поведение (30ac60da).
- `followup_40h`: `FollowupPolicy::due()` читает `state.counters.silence_started_at`, если он
  выставлен, вместо живого `$lastAgent`; `FollowupSender.php` пишет этот флаг при первой отправке.
  `manual.followup_40h` совпадает с записью на всех шагах.
- `FirstTurnGoal.php:52` (`isFirstTurn` `false`→`true`) — гипотеза проверена и отвергнута ранее: ломает
  `FirstTurnStateTest::test_first_client_message_follows_donor_inbound_goal_not_proactive_opening`.

## Раунд 6 (08.11, по заданию реопена) — новая попытка и её результат
- Испробовал: засеять conversation как уже «после опенинга» (flags.introduced=true, stage=qualify,
  version=1 — как итоговое состояние `ApiOpeningRuntime` для persona.*) перед первым ходом клиента
  для всех 9 диалоговых кейсов, чтобы ход шёл через `FollowupCoordinator`, а не через
  `FirstTurnCoordinator`/`FirstTurnGoal`.
- Результат: байт-в-байт сверка (дамп SENT/RECORDED) показала, что это **ломает уже рабочий extractor**
  — записанный запрос экстрактора у донора сам показывает `стадия: open` (а не `qualify`), т.е. донор
  на этом шаге ещё холодный. Расходится только RESPONDER-запрос той же самой реплики, потому что
  `Policy::decide()` переводит стадию в `qualify` только в ветке `intro_and_ask` (первый ход), а не в
  ранних ветках `closed_topic`/`busy_now`/`unknown_fact`/`duplicate_lead`, которые у всех 9 кейсов.
- Вывод: фикса на стороне теста нет. Единственный сигнал «это первый ход» (`isFirstTurn` или
  `counters.turns === 1`) неотличим от сценария пиненного `FirstTurnStateTest` — там тот же сигнал
  должен оставить `introduced=false`. Правка `Policy.php` под все ранние ветки сломает тот же пин.
- Откатил experimental seeding (revert к состоянию main), оставил объясняющий комментарий в
  `RuntimeReplayE307GTest.php` у пина, чтобы раунд 7 не повторял тот же эксперимент.

## Тесты
- `vendor/bin/phpunit --testsuite=Equivalence --no-coverage`: 9/9, 4637 проверок (без изменений
  поведения — подтверждает, что текущее пиненное состояние не регрессировало).
- `composer lint` (Pint) — зелёный.

## Остаток
- 9 диалоговых + `no_repeat_filled_slot` + `manual.force_close_14`: подтверждённое архитектурное
  расхождение — `Policy::decide()` переводит стадию в `qualify` на первом ходу только для ветки
  `intro_and_ask`, донор делает это для любого интента. Исправление требует либо менять
  `FirstTurnStateTest` (пин, решение не для исполнителя), либо вводить новый сигнал, которого сейчас
  в проекте нет.

## Нужно от интегратора
- Решить: либо закрыть этот пункт как постоянное документированное ограничение equivalence (без
  дальнейших реопенов SP-E3-07G по этой причине), либо отдельной задачей с явным согласием владельца
  менять пин `FirstTurnStateTest::test_first_client_message_follows_donor_inbound_goal_not_proactive_opening`.
