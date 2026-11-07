# SP-E3-07G — рабочий runtime replay

Полный разбор: `docs/validation/SP-E3-07G-runtime-replay.md`.

- Нашёл и починил две причины, из-за которых ни один записанный вызов не проходил: `RecordedGateway` хешировал
  плоский `AiRequest::toArray()` против вложенного донорского `{messages, options}` (несовпадение гарантировано
  всегда, не текстовое расхождение), и `usage` в записи был snake_case, а `assertResult()` ждёт camelCase. После
  правки extractor-запрос реального runtime побайтово совпал с записанным (`manual.closed_topic`,
  `persona.newbie_voronezh`) — доказательство эквивалентности промпта прототипу, не хеш.
- Новый `tests/Equivalence/RuntimeReplayE307GTest.php`: реальный пайплайн (`FirstInboundRuntime` →
  `FirstTurnCoordinator`/`FollowupCoordinator` → `Gateway`) по 9 диалоговым manual.*-кейсам и 2 таймерным.
  `followup_20h/40h` воспроизведены целиком через реальный `PrepareFollowups`+`FollowupSender`: `followup_20h`
  совпадает на 100%, `followup_40h` — первое напоминание совпадает (Техдолг про второе). У 9 диалоговых кейсов
  extractor совпадает с записью; responder блокирован точно локализованной причиной вне scope — тест фиксирует
  это `assertSame` на конкретное сообщение отказа, а не проходит вслепую.
- Проверки: `php artisan test tests/Equivalence tests/Feature/Equivalence` — 11/11 зелёные, 4611+ проверок; Pint.
  Golden fixtures не менялись.

## Остаток
- Responder-карточка LeadState показывает стадию до перехода (`open` вместо `qualify`), хотя `Policy::decide()`
  (app/Seller/Engine/Policy.php:361) мутацию уже делает — расхождение в `SellerResponderStages.php`/
  `FirstTurnState.php` (не в scope). Терминальные переходы (lost/handoff) уже совпадают.
- `persona.*` (29) открываются ходом без клиента — это `ApiOpeningRuntime` (не в scope), без него нет входной
  точки для хода 0 такого диалога.
- `manual.force_close_14` не прогонялся: содержит периодические `summary`-вызовы вне нашего пути.

## Повторная сдача после reopen (08.11 00:35)
Карточка переоткрыта с тем же остатком (responder-стадия, 29 persona, force_close_14, followup_40h).
Все четыре пункта требуют файлов вне текущего scope (`app/Seller/Runtime/`, `app/Ai/Protected/SellerResponderStages.php`,
`app/ProtectedOperations/FirstTurnState.php`, `app/Seller/Engine/Policy.php`, `app/Seller/Followups/FollowupPolicy.php` —
scope задачи их не включает, сужен планировщиком 07.11 23:30). Новых правок без расширения scope не делал; перепрогнал
тесты — зелёные, те же 11/11. Регистрация теста в CI (прошлый пункт «Нужно от интегратора») сделана интегратором
отдельным коммитом (phpunit.xml, equivalence job) — закрыто.

## Нужно от интегратора
- Scope на `SellerResponderStages.php`, `FirstTurnState.php`, `Policy.php` (мутация стадии теряется между целью
  хода и карточкой для responder) — либо отдельная карточка на этот конкретный баг.
- Scope на `app/Seller/Runtime/ApiOpeningRuntime.php` для 29 persona-сценариев.
- Scope на `app/Seller/Followups/FollowupPolicy.php` для второго автонапоминания (см. Техдолг) — если решат чинить,
  а не оставлять как есть у прототипа-движка.

## Техдолг
- `FollowupPolicy::due()` (не в scope): второе автонапоминание отмеряется от последнего напоминания, а не от
  начала молчания — донор ждёт 40ч от старта, у нас 60ч. Подтверждено `manual.followup_40h`.

## Принято по умолчанию
- `manual.no_repeat_filled_slot` воспроизведён как follow-up на синтетическом ходе 1: настоящий ход 0 обязан
  начинаться с пустого LeadState.
