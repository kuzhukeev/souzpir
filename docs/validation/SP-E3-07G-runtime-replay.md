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
     `PrepareFollowups`+`FollowupSender` (реальный планировщик, без LLM): `followup_20h` совпадает с записью на 100%
     (0 → 1 напоминание на ~20 ч тишины); `followup_40h` совпадает в первом напоминании, расходится во втором (см. Техдолг).

## Что блокирует responder-стадию (одинаково во всех 9+29 диалоговых кейсах)

Donor-карточка LeadState для responder-промпта показывает `стадия: qualify`, наш runtime — `стадия: open`, хотя сам текст
«ЦЕЛЬ ХОДА» (вычисляемый той же функцией `Policy::decide()`, которая и переводит стадию в `qualify` на первом ходу,
`app/Seller/Engine/Policy.php:361`) уже корректный. То есть мутация стадии происходит, но не попадает в тот экземпляр
`LeadState`, из которого строится карточка для промпта responder'а — видимо, карточка рендерится из состояния, прочитанного
заново (до записи мутации), а не из того же объекта. Воспроизводится стабильно для «продолжаем квалификацию» (closed_topic,
large_order, prompt_injection, deferred_twice, off_topic_three — везде `open` вместо `qualify`), но НЕ для немедленных
терминальных переходов (`opt_out`→`lost`, `wants_human`/`negative_twice`→`handoff`/`lost` — там совпадает). Код, который на
это влияет: `app/Ai/Protected/SellerResponderStages.php`, `app/ProtectedOperations/FirstTurnState.php`,
`app/Seller/Engine/Policy.php` — ни один не входит в scope SP-E3-07G.

## Персона-сценарии (29 из 41) — отдельная причина

`persona.*` начинаются ходом БЕЗ входящего сообщения клиента (бот сам открывает диалог) — это `ApiOpeningRuntime`
(`app/Seller/Runtime/`), исключённый из scope задачи. `FirstInboundRuntime`/`FirstTurnCoordinator` требуют реального
клиентского сообщения на ходу 0 (`FirstTurnProtectedClaim::create()` требует `state === []`, `stage === 'open'` —
воспроизвести миддл-диалоговое открытие без прохода через настоящий ход 0 нельзя). Поэтому персона-кейсы не входят в
`DIALOGUE_CASES` этого теста.

## `manual.force_close_14` — не включён

14 ходов, 31 LLM-вызов; среди них периодические `component: summary` (каждые ~12-13 ходов), которых наш движок в этом цикле
ходов не вызывает (`SellerSummaryStages` существует, но не на этом пути) — курсор записи разъехался бы на первом же
summary-вызове. Нужна отдельная проверка, когда summary вызывается в реальном коде.
