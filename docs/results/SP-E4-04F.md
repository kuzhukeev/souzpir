# SP-E4-04F

- `log_unknown_question` подключён к общему диспетчеру `ToolRunner`: валидирует аргументы и возвращает `logged` вместо `unavailable` (раньше модель получала «недоступно» на разрешённый инструмент).
- `Policy::applyExtraction` теперь вызывает `LeadState::addUnknownQuestion` — счётчик `unknown_facts` и список `unknown_questions` в состоянии диалога больше не мёртвый код, пополняются на последующих ходах (проверено через `/seller/turns/runtime`, followup).
- Аналитика экрана тем (`unknownRates`, доля «не знаю») не менялась — уже считает по `meta.goal_kind`; подтверждено прогоном `TopicStatisticsTest` без изменений.
- Проверено: `tests/Unit/Seller/Engine/*`, `tests/Feature/Seller/Runtime/*` (новый `UnknownQuestionTaskE404FTest`: followup-ход пишет вопрос и счётчик в состояние диалога, брендовая изоляция), `tests/Feature/Seller/Knowledge/Topics/*`, `ProtectedSimulationRuntimeTest`, `TaskBoardTest` — все зелёные; pint — passed.

## Остаток
- Создание задачи менеджеру `seller_tasks` (`type=unknown_question`, «обещание ответить сегодня») при `goal->kind === 'unknown_fact'` — не сделано: единственное корректное место (финализация хода) занято другой задачей, см. «Нужно от интегратора».
- Первый ход (`FirstTurnCoordinator`/`FirstTurnGoal`) не вызывает `Policy::applyExtraction` вовсе — `addUnknownQuestion` и счётчики там не применяются даже после этой правки; это отдельная, более широкая дыра (стрики/стадия/clientType), не трогал.

## Техдолг
- `FirstTurnGoal::evaluateLocked` пропускает `Policy::applyExtraction` — несимметрично с followup-путём (`ApiFollowupInput`), затрагивает не только unknown-факты.

## Принято по умолчанию
- Текст неизвестного вопроса берётся как есть из `extraction['unknown_question']`, без нормализации (совпадает с существующей обработкой `unknown_insist`).

## Нужно от интегратора
- `app/ProtectedOperations/FirstTurnTextFinalizer.php` и `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php` сейчас в scope SP-E7-04M (в работе, exec-3, уточнение 07.11 20:10) — не трогал. После её `done` добавить в обоих `write()`/`finishProtectedApi()` при `$goal->kind === 'unknown_fact'`: вставку в `seller_tasks` (`type='unknown_question'`, `payload.text` с вопросом, `due_at` = конец дня) — по образцу `App\Seller\Engine\CreatePromise`. Тогда же снять этот пункт и пункт «Остаток» выше.
