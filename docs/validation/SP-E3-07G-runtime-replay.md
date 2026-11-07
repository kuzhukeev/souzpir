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

## Обновление 08.11.2026 (расширенный scope): persona.*, force_close_14, followup

- **29 `persona.*` через `ApiOpeningRuntime`** (`tests/Equivalence/RuntimeReplayE307GTest.php::test_persona_cases_open_through_the_api_opening_runtime`):
  открытие идёт отдельным путём (`ApiOpeningRuntime::open()` → `Gateway::completeApiOpening()` → `ApiOpeningInput::buildLocked()`),
  который строит карточку и мутирует стадию в ОДНОМ и том же объекте `LeadState` — поэтому responder-стадийный баг выше
  (SellerResponderStages/FirstTurnState) здесь не проявляется. Реальный пайплайн вызывает драйвер для всех 29 кейсов, и
  отрендеренный системный промпт (карточка клиента, текст цели, модель, температура) побайтово совпадает с записью донора
  — проверено сравнением через `CanonicalJson::hash()` на `messages`+остальные опции, **кроме** `tools`/`tool_choice`.
  Блокирует не responder-стадия, а отдельный баг: `app/Ai/Protected/ApiOpeningInput.php` вызывает
  `ResponderRequest::build($system, [], [], ...)` с пустым списком разрешённых инструментов вместо `$goal->allowedTools`
  (сравните `app/Ai/Protected/SellerResponderStages.php:229`, где для обычного хода передаётся `$goal->allowedTools`
  корректно). Файл вне scope SP-E3-07G — см. «Нужно от интегратора» в docs/results/SP-E3-07G.md.
- **`manual.force_close_14`**: добавлен в `DIALOGUE_CASES` — он спотыкается на той же самой турн-0 responder-блокировке,
  что и остальные 8 диалоговых кейсов, ДО того, как сценарий успевает дойти до периодического `summary`-вызова
  (курсор записи останавливается на вызове №2 из 31). Отдельная обработка `summary`-ветки не нужна для текущего scope.
- **`followup_40h` (`FollowupPolicy`)**: точная причина расхождения найдена. `FollowupPolicy::due()` считает второй порог
  от `$lastAgent` (текущее `last_bot_message_at`), а `FollowupSender.php:63` при отправке напоминания перезаписывает
  `last_bot_message_at = now()`. Значит после первого напоминания эталонная точка «начало тишины» теряется: второй порог
  отмеряется от момента ПЕРВОГО напоминания (ещё +40ч), а не от начала тишины клиента (донор). Корректное исправление
  требует не трогать `last_bot_message_at` при отправке автонапоминаний (или завести отдельное поле «начало тишины») —
  это `app/Seller/Followups/FollowupSender.php`, который сейчас вне scope карточки (сузили в «Сужение scope» выше, туда
  не входит). Правка одного `FollowupPolicy.php` без `FollowupSender.php` either не меняет это поведение, либо (если
  считать от `$lastClient` вместо `$lastAgent`) ломает сценарий с реальной задержкой ответа менеджера —
  `tests/Unit/Seller/Followups/FollowupPolicyTest.php` специально проверяет именно этот случай (`last_client_message_at`
  и `$lastAgent` расходятся на час). См. «Нужно от интегратора» в docs/results/SP-E3-07G.md.
