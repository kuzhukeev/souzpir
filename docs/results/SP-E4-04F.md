# SP-E4-04F

Остаток «07.11.2026 23:40»: при `goal->kind === 'unknown_fact'` финализаторы `FirstTurnTextFinalizer::write()` и
`FollowupFinalizer::finishProtectedApi()` теперь создают задачу менеджеру `seller_tasks` (`type='unknown_question'`,
`payload.text`/`payload.question` = вопрос клиента, `due_at` = конец дня по таймзоне бренда), по образцу
`App\Seller\Engine\CreatePromise`; сам `CreatePromise` не менялся.

- Проверено: новый `tests/Feature/Seller/Knowledge/Topics/UnknownQuestionManagerTaskTest.php` — первый ход (`/seller/turns/first`)
  и последующий ход (`/seller/turns/runtime`): задача создаётся в своём бренде с нужным payload/due_at, у второго бренда задач нет
  (изоляция). Регрессия: `tests/Feature/Seller/Knowledge/Topics/*` (6), `tests/Feature/Seller/Runtime/UnknownQuestionTaskE404FTest`,
  `tests/Feature/Seller/Channels/Api/ApiOpeningE503FTest` (оба финализатора напрямую), `tests/Unit/Seller/Engine/*` (121) — все прошли.
  `pint`: passed. `check lint`: passed.
- Ранее сделано (слито 07.11): log_unknown_question подключён к ToolRunner, счётчик unknown-вопросов в Policy::applyExtraction.

## Техдолг
- `tests/Feature/ProtectedOperations/FirstTurnStateTest.php` падает в этом окружении уже до моих правок («An isolated test
  database is required» — требует непривилегированную Postgres-роль) — не связано с задачей.

## Принято по умолчанию
- В payload задачи пишется и `text`, и `question` (одинаковое значение) — по уточнению планировщика 00:30 для экрана D-3.3.
- due_at считается от таймзоны бренда, без доп. UTC-конвертации в payload (как в остальных seller_tasks).
