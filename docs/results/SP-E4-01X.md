# SP-E4-01X — остаток reopen 08.11 02:40

## Повторная сдача 08.11 03:xx
- Код без изменений (коммит `b9a168d5`, CI ветки был зелёный: run 42427972685). Зависимости SP-E4-01AB и SP-E6-04G влиты в main — проверено, что это не меняет блокер «draft-preview через first-turn стадии»: `Gateway`/`FirstTurnDeterministicGuard` в main не менялись (оба merge принесли только docs/tests). Тесты повторно прогнаны на текущем состоянии ветки — те же 16, зелёные (359 assertions); `check lint` — успешно.

## Сделано
- Карта `ESCALATE_EVENTS` (персона → произвольные поля события) убрана. Событие `handoff` теперь строится из реального аргумента `reason` вызова `escalate` (`$call['arguments']`), переведённого таблицей `ESCALATE_REASON_LABELS` (инженерный id причины → метка каталогов R/S): `want_human→requested_by_client`, `defect_claim→defect`, `container_import→container_import`, `deferred_insist→repeated_deferred_request`. `$toolResults` теперь хранит `arguments` вызова, а не только `name`/`status`.
- `for_self_wedding`, `prices_only_rude`, `requisites`, `big_order` исключены из таблицы: они никогда не производили реальный вызов `escalate` (retail/`big_order` идут через `notify_manager`-эффект, `requisites` — через `send_requisites`), событие для них и раньше не могло сработать — явного регресса нет, просто убрана мёртвая и неверная (привязанная к персоне, а не к аргументам) карта.
- Новый тест `DraftContextCatalogE401XTest` вместо вызова приватного `matchContext` через reflection реально сеет персону `wants_human` (`SellerPersonaSeeder`, хеш совпадает с `personas.yaml`), прогоняет настоящий сценарий через `/seller/knowledge/{id}/scenarios`, затем вызывает публичный `FixtureRuntime::checkPartial` тем же request (через `TenantContextRunner`, т.к. HTTP-ядро сбрасывает scoped `TenantContext` после ответа) с контекстом каталога S (`wants_human/handoff_fast`) — получает реальное `true` от каталога, `null` без события и без совпадающей истории.

## Тесты
- `DraftContextCatalogE401XTest` — 1 тест, 5 assertions (без reflection).
- `ConfirmedHandoffE408KTest` обновлён под новую форму `tool_results` (добавилось поле `arguments`).
- Прогнаны: DraftScenarioPreviewE401ITest, DraftFixtureRuntimeE401LTest/MTest, DraftScenarioRunE401ITest, DraftSeedFixturesE401NTest, ConfirmedHandoffE408KTest, SimulationBridgeE306DTest, SandboxTurnsE407CTest, SandboxReplyE409BTest, SandboxHistoryE410FTest, PublishedFieldsSandboxReplyE610ETest, JobFirstTurnInputE306FTest — 16 тестов, все зелёные. Pint и `check lint` — успешно.

## Остаток
- "прогон сценария с реальным совпадением" достижим сегодня только через прямой вызов `checkPartial` с контекстом каталога (как в новом тесте), не через обычный `dialogue()`: генератор клиентских реплик персон всегда выдаёт синтетику («Уточню: <факт>»/вариант из каталога Q), которая никогда не совпадает буквально с естественным текстом каталогов R/S ни для одной из 29 персон — структурный разрыв, не specific к `wants_human`, уже описан в прежнем «Остатке» (SP-E4-01O/X); выравнивание генерации — отдельная задача.
- Пункт «draft-preview через защищённые first-turn стадии» не трогал: `FirstTurnDeterministicGuard`/`Gateway` работают с реальным опубликованным ходом через БД-блокировки (`c02_responder_stages`, `e309_responder_continuations`), с черновиком (неопубликованным релизом) несовместимы без рефакторинга — решение владельца 06.11 запрещает рефакторинг и изменение поведения общих AI-стадий. См. «Нужно от интегратора».

## Техдолг
- `ESCALATE_REASON_LABELS` всё ещё не покрывает `recipient` (каталог `big_order`) — такого аргумента в схеме `escalate` нет и в реальности не производится; критерий остаётся «неизвестно», без регресса.

## Нужно от интегратора
- Пункт «draft-preview через защищённые first-turn стадии» в reopen повторяет формулировку исходного приёмочного пункта (закрытого прежним «Принято по умолчанию» от 06.11/прошлой сдачи, без нового техдолга в tech-debt.md). Нужна явная переформулировка, что конкретно не устроило в прошлой сдаче, иначе карточка будет сдаваться в этом же виде повторно.

## Принято по умолчанию
- Таблица меток причин ограничена случаями, где escalate реально вызывается с `reason` (4 позиции); остальные персоны каталогов R/S по-прежнему не производят `handoff`-событие — то же поведение, что было после прошлой сдачи, просто честно без мёртвой карты по персоне.
