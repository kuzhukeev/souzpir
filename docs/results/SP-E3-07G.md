# SP-E3-07G — рабочий runtime replay

Полный разбор: `docs/validation/SP-E3-07G-runtime-replay.md`.

## Сделано в этой итерации (переоткрытие 08.11.2026, scope без изменений: `ApiOpeningRuntime.php`,
`FollowupPolicy.php`, `Policy.php`, тесты, docs)
- Код в scope не менялся — сверка подтвердила, что уже сделанное (ниже) не регрессировало: `vendor/bin/phpunit
  tests/Equivalence/RuntimeReplayE307GTest.php --no-coverage` — 4/4, 132 проверки; `tests/Unit/Seller/Followups/
  FollowupPolicyTest.php` — 3/3. Коммит на main с этой работой: `f5cc8fcebf045991bbb56c3af3d9db63dfb3b5fd` (и
  следующие на этой ветке, тот же набор).
- Разобрал responder-стадию заново (цель переоткрытия) и нашёл точный root cause вместо прежнего общего указания на
  два файла — см. «Нужно от интегратора»: `app/ProtectedOperations/FirstTurnGoal.php:52`, захардкоженный
  `isFirstTurn = false`. Все три блокера («Нужно от интегратора» ниже) требуют файлов вне текущего scope; в
  scope карточки больше нечего делать.
- **29 `persona.*` через `ApiOpeningRuntime`** — новый `test_persona_cases_open_through_the_api_opening_runtime`
  в `tests/Equivalence/RuntimeReplayE307GTest.php`: реальный HTTP-путь `/api/v1/leads?action=open` →
  `ApiOpeningRuntime::open()` → `Gateway::completeApiOpening()`. Для всех 29 кейсов вызов реально доходит до
  драйвера, и отрендеренный системный промпт (карточка LeadState, цель хода, модель, температура) **побайтово
  совпадает** с записью донора (проверено `CanonicalJson::hash()`, без tools/tool_choice — см. ниже). Этот путь не
  задевает известный responder-stage баг (SellerResponderStages/FirstTurnState), т.к. мутация стадии и рендер карточки
  идут в одном объекте `LeadState`. Блокирует отдельная, точно локализованная причина вне scope — см. «Нужно от
  интегратора».
- **`manual.force_close_14`** добавлен в `DIALOGUE_CASES` — спотыкается на той же турн-0 responder-блокировке, что и
  остальные 8 кейсов, до того как сценарий успевает дойти до периодического `summary`-вызова; отдельная обработка не
  нужна.
- **Точный диагноз `followup_40h`** (не правил, см. «Нужно от интегратора»): причина — `FollowupSender.php:63`
  перезаписывает `last_bot_message_at = now()` при отправке автонапоминания, из-за чего `FollowupPolicy::due()`
  теряет исходную точку «начало тишины» и отмеряет второй порог от первого напоминания, а не от начала молчания.
  Пробовал править только `FollowupPolicy.php` (считать от `last_client_message_at`) — чинит `followup_40h`, но
  ломает `tests/Unit/Seller/Followups/FollowupPolicyTest.php` (сценарий с реальной задержкой ответа менеджера, где
  `last_client_message_at` и `last_bot_message_at` расходятся на час) — откатил, правка требует `FollowupSender.php`
  вне scope.
- **CI**: наш файл `tests/Equivalence/RuntimeReplayE307GTest.php` никогда не входил ни в один phpunit-suite и не
  запускался job `equivalence` (прошлая заметка «закрыто интегратором» относилась к другому файлу,
  `RuntimeReplayE307ETest.php`, зарегистрированному в `.github/workflows/ci.yml` отдельным шагом). `phpunit.xml` вне
  scope карточки — регистрация в «Нужно от интегратора».
- Проверки: `vendor/bin/phpunit --testsuite=Equivalence --no-coverage` — 5/5 (без нашего файла, не зарегистрирован,
  см. выше), `vendor/bin/phpunit tests/Equivalence/RuntimeReplayE307GTest.php --no-coverage` — 4/4, 132 проверки
  (все зелёные); `tests/Unit/Seller/Followups/FollowupPolicyTest.php` — не задет (3/3 зелёные); Pint чистый. Golden
  fixtures не менялись.

## Остаток
- Responder-карточка LeadState в обычном ходе (9 диалоговых + 1 no_repeat_filled_slot) показывает стадию до
  перехода (`open` вместо `qualify`). Терминальные переходы (lost/handoff) уже совпадают. Точный root cause найден
  (см. «Нужно от интегратора») — `FirstTurnGoal.php:52`, не в scope.
- `persona.*` (29): сам опенинг доказан эквивалентным (см. выше); продолжение диалога (ходы 1+) пойдёт через тот же
  responder-stage баг, что и диалоговые кейсы — не прогонялось отдельно, т.к. блокер идентичен уже зафиксированному.
- `followup_40h`: второе напоминание (Техдолг + «Нужно от интегратора» ниже).

## Нужно от интегратора
- Точный root cause найден повторным разбором (переоткрытие 08.11): `app/ProtectedOperations/FirstTurnGoal.php:52`
  вызывает `(new Policy($knowledge))->decide($lead, $extraction, false)` с захардкоженным `isFirstTurn = false`, хотя
  класс целиком про первый ход — единственный такой вызов во всём коде (сравните `ApiOpeningInput.php:53`: `true`;
  `SandboxRuntime.php:37`: `$history === []`; `SandboxController.php:71`: `$index === 0`). Из-за этого ветка
  `Policy.php:359` (`if ($isFirstTurn && ! $s->flag('introduced'))`, ставит `qualify`) не срабатывает на первом ходу —
  отсюда `open` вместо `qualify` в карточке (9+29 кейсов). Готовая правка: заменить `false` на `true` на этой строке.
  Это баг не только эквивалентности — флаг `introduced` и переход в `qualify` реально не происходят этим путём в
  проде. Scope на `FirstTurnGoal.php` не входит в SP-E3-07G.
- Scope на `app/Ai/Protected/ApiOpeningInput.php:70` — вызывает `ResponderRequest::build($system, [], [], ...)` с
  пустым списком инструментов вместо `$goal->allowedTools` (сравните корректный вызов в
  `SellerResponderStages.php:229`). Готовая правка: заменить третий аргумент на `$goal->allowedTools`. Это единственный
  блокер для всех 29 persona-кейсов (запрос иначе байт-в-байт совпадает с записью).
- Scope на `app/Seller/Followups/FollowupSender.php:63` — не перезаписывать `last_bot_message_at` при отправке
  автонапоминания (или завести отдельное поле «начало тишины»), чтобы `FollowupPolicy::due()` мог измерять второй
  порог от начала молчания, а не от последнего напоминания.
- `phpunit.xml` вне scope карточки: `tests/Equivalence/RuntimeReplayE307GTest.php` не входит ни в один testsuite и
  не запускается CI job `equivalence`. Готовая правка: добавить `<file>tests/Equivalence/RuntimeReplayE307GTest.php</file>`
  в `<testsuite name="Equivalence">` рядом с `tests/Feature/Equivalence` (workflow трогать не нужно — job уже
  выполняет `--testsuite=Equivalence`).

## Техдолг
- `FollowupPolicy::due()`/`FollowupSender.php`: см. «Нужно от интегратора» — второе автонапоминание уходит позже
  донора (40ч от старта тишины у донора, 60ч у нас). Подтверждено `manual.followup_40h`.

## Принято по умолчанию
- `manual.no_repeat_filled_slot` воспроизведён как follow-up на синтетическом ходе 1: настоящий ход 0 обязан
  начинаться с пустого LeadState.
- Для persona-кейсов валидный лид создаётся только с полем `phone` (не `name`/`email`) — иначе `createMappedLead`
  заполняет слот LeadState и карточка расходится с донорским «пока ничего не известно».
