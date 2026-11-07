# SP-E3-07G — рабочий runtime replay

Полный разбор: `docs/validation/SP-E3-07G-runtime-replay.md`.

## Сделано в этой итерации (расширенный scope 08.11.2026)
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
  `RuntimeReplayE307ETest.php`, зарегистрированному в `.github/workflows/ci.yml` отдельным шагом). Зарегистрировал
  файл в `phpunit.xml` → testsuite `Equivalence` (workflow не трогал — он вне scope и уже покрывает
  `--testsuite=Equivalence`).
- Проверки: `vendor/bin/phpunit --testsuite=Equivalence --no-coverage` — 9/9 зелёных, 4638 проверок;
  `tests/Unit/Seller/Followups/FollowupPolicyTest.php` — не задет (3/3 зелёные); Pint чистый. Golden fixtures не
  менялись.

## Остаток
- Responder-карточка LeadState в обычном ходе (9 диалоговых + 1 no_repeat_filled_slot) показывает стадию до
  перехода (`open` вместо `qualify`) — расхождение в `SellerResponderStages.php`/`FirstTurnState.php` (не в scope).
  Терминальные переходы (lost/handoff) уже совпадают.
- `persona.*` (29): сам опенинг доказан эквивалентным (см. выше); продолжение диалога (ходы 1+) пойдёт через тот же
  responder-stage баг, что и диалоговые кейсы — не прогонялось отдельно, т.к. блокер идентичен уже зафиксированному.
- `followup_40h`: второе напоминание (Техдолг + «Нужно от интегратора» ниже).

## Нужно от интегратора
- Scope на `SellerResponderStages.php`, `FirstTurnState.php`, `Policy.php` — мутация стадии теряется между целью
  хода и карточкой для responder (9+29 кейсов).
- Scope на `app/Ai/Protected/ApiOpeningInput.php:70` — вызывает `ResponderRequest::build($system, [], [], ...)` с
  пустым списком инструментов вместо `$goal->allowedTools` (сравните корректный вызов в
  `SellerResponderStages.php:229`). Готовая правка: заменить третий аргумент на `$goal->allowedTools`. Это единственный
  блокер для всех 29 persona-кейсов (запрос иначе байт-в-байт совпадает с записью).
- Scope на `app/Seller/Followups/FollowupSender.php:63` — не перезаписывать `last_bot_message_at` при отправке
  автонапоминания (или завести отдельное поле «начало тишины»), чтобы `FollowupPolicy::due()` мог измерять второй
  порог от начала молчания, а не от последнего напоминания.

## Техдолг
- `FollowupPolicy::due()`/`FollowupSender.php`: см. «Нужно от интегратора» — второе автонапоминание уходит позже
  донора (40ч от старта тишины у донора, 60ч у нас). Подтверждено `manual.followup_40h`.

## Принято по умолчанию
- `manual.no_repeat_filled_slot` воспроизведён как follow-up на синтетическом ходе 1: настоящий ход 0 обязан
  начинаться с пустого LeadState.
- Для persona-кейсов валидный лид создаётся только с полем `phone` (не `name`/`email`) — иначе `createMappedLead`
  заполняет слот LeadState и карточка расходится с донорским «пока ничего не известно».
