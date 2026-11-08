# SP-E3-07G — рабочий runtime replay: что воспроизведено и что блокирует остальное

## Что сделано (реальный прогон, не диагностика)

1. **`tests/Feature/Equivalence/Support/RecordedGateway.php`** — донор хранит запрос как `{messages, options{component,model,...}}`,
   а живой `AiRequest::toArray()` — плоский `{messages, model, ...}`. Старое сравнение хешировало эти две разные формы
   напрямую, поэтому не совпадало НИКОГДА, независимо от содержимого. Починено: обе формы сводятся к одной сравнимой
   структуре перед хешированием; `component` (донорская метка, которой нет в реальном запросе) убран с обеих сторон —
   какой стадии принадлежит вызов, уже гарантирует курсор по записям кейса. Списки `enum`/`required` внутри JSON-схемы —
   неупорядоченные множества; перестановка их членов (например, порядок `LeadState::SLOTS` после рефакторинга) больше не
   считается несовпадением запроса.
2. Там же: `usage.prompt_tokens`/`completion_tokens` (донорский snake_case) переведены в `promptTokens`/`completionTokens`,
   как это делает `OpenRouterDriver` — без этого `Gateway::assertResult()` всегда отклонял результат как «Invalid AI driver usage».
3. **После этих двух правок extractor-запрос реального runtime побайтово совпал с записанным** (проверено построчно для
   `manual.closed_topic`: системный промпt 8927 байт идентичен донору; то же для первого extractor-вызова
   `persona.newbie_voronezh`). Это и есть доказательство эквивалентности промптов прототипу на уровне extractor — не хеш,
   а текст.
4. **`tests/Equivalence/RuntimeReplayE307GTest.php`** (новый файл) гоняет реальный защищённый пайплайн
   (`FirstInboundRuntime` → `FirstTurnCoordinator`/`FollowupCoordinator` → `Gateway`) по записанным ответам:
   - `manual.closed_topic, large_order, opt_out, prompt_injection, wants_human, deferred_twice, negative_twice,
     off_topic_three, no_repeat_filled_slot` — extractor реально вызывается и совпадает с записью (пройдено).
   - `manual.followup_20h`, `manual.followup_40h` — таймерные сценарии воспроизведены целиком через
     `PrepareFollowups`+`FollowupSender` (реальный планировщик, без LLM): оба совпадают с записью на 100% (`followup_40h`
     исправлен в реопене 08.11 — см. ниже).

## Реопен 08.11.2026 (расширенный scope) — что исправлено, что проверено и отвергнуто

- **`FirstTurnGoal.php:52` (`isFirstTurn` `false`→`true`) — гипотеза проверена и отвергнута.** Подставлена,
  локально карточка LeadState стала показывать `стадия: qualify` как у донора (вместо `open`) — выглядело как
  фикс. Но CI показал: правка ломает `FirstTurnStateTest::test_first_client_message_follows_donor_inbound_goal_not_proactive_opening`
  и смежные тесты того же файла — они фиксируют, что первое сообщение клиента в этом приложении осознанно
  соответствует донорскому ходу ПОСЛЕ проактивного опенинга (`ask_slot`/`answer_and_ask`), а не самому опенингу
  (`intro_and_ask`): опенинг в проде идёт отдельным механизмом (настройка приветствия, `GreetingFirstTurnTest`),
  а не через эту ветку `Policy::decide()`. Совпадение `стадия: qualify` в DIALOGUE_CASES было случайным — эти
  донорские трейсы сами предполагают уже прошедший опенинг, то есть ожидаемая цель хода там тоже `ask_slot`, а не
  `intro_and_ask`; выставление `isFirstTurn=true` даёт правильный текст стадии, но неправильную цель хода. Правка
  откатана, `false` оставлен с комментарием-ссылкой на этот пин. Мismatch `open` вместо `qualify` в 9+29
  диалоговых кейсах остаётся прежним — см. «Остаток» в docs/results/SP-E3-07G.md.
- **`ApiOpeningInput.php:70`**: третий аргумент `ResponderRequest::build()` заменён `[]` → `$goal->allowedTools`.
  Все 29 `persona.*` теперь проходят `test_persona_cases_open_through_the_api_opening_runtime` полностью (были
  заблокированы пустым списком инструментов). Обновлён `ApiOpeningInputE503FTest` (ранее фиксировал баг как ожидаемое
  поведение).
- **`FollowupSender.php`/`PrepareFollowups.php`**: запрос «последнего агентского сообщения» (база отсчёта тишины для
  `FollowupPolicy::due()`) теперь исключает сообщения-напоминания (`meta.goal.kind = followup_nudge`), так что второй
  порог считается от начала тишины, как у донора, а не от момента первого напоминания. `manual.followup_40h` теперь
  совпадает с записью на всех шагах (было 60ч вместо 40ч для второго напоминания).

## Персона-сценарии (29 из 41) — закрыто

`persona.*` начинаются ходом БЕЗ входящего сообщения клиента (бот сам открывает диалог) через `ApiOpeningRuntime`/
`ApiOpeningInput`. После правки allowedTools (выше) все 29 кейсов проходят байт-в-байт (включая `tools`/`tool_choice`).

## `manual.force_close_14` — не включён

14 ходов, 31 LLM-вызов; среди них периодические `component: summary` (каждые ~12-13 ходов), которых наш движок в этом цикле
ходов не вызывает (`SellerSummaryStages` существует, но не на этом пути) — курсор записи разъехался бы на первом же
summary-вызове. Нужна отдельная проверка, когда summary вызывается в реальном коде.
