# SP-E3-06L — защищённый fake-ход job

- Подключили job-claim первого входящего сообщения (JobFirstTurnClaim, SP-E3-06G) к общему
  gateway/extractor/responder/guard/финализации: `ApiFollowupInput::extractorLocked` теперь
  различает симуляцию (idempotency_key `sim:*`) и настоящий job-claim первого хода (новая
  ветка `jobFirstTurn`, авторизация через `JobFirstTurnClaim::assertCurrent`); дальше работает
  без изменений существующий путь responder/guard/`FollowupFinalizer`.
- Добавили `FollowupCoordinator::runFirstTurnJob(tenant, conversationId, messageId, key)` —
  claim → `orchestrateTurn(..., 'bot')`; завершённый ход реплеится по `seller_turns.idempotency_key`
  без повторного вызова `claim()` (повторный `resolve()` иначе падает — версия беседы уже не 0).
  Очередь не подключена (вызов прямой, как и `runJob` для симуляции).
- Проверено: полный fake-ход (claim → extractor → responder → finalize, сообщение и версия
  беседы записаны), восстановление/реплей хода без дублирования AI-вызовов и сообщений,
  изоляция бренда и источника (чужой бренд/`session`-источник отклонены), невалидный
  conversation_id, запрет live-драйвера.
- PHPUnit `JobFirstTurnFakeRunE306LTest`: 2 теста, 14 проверок, отдельная PostgreSQL.
  Регрессия: `ProtectedSimulationRuntimeTest`, `SimulationBridgeE306DTest`, `ApiOpeningE503FTest`,
  `ApiTokenRuntimeE503CTest`, `JobExtractorLoadE306KTest`, `JobExtractorStorageE306JTest`,
  `JobFirstTurnClaimE306GTest`, `JobFirstTurnInputE306FTest` — все проходят без изменений
  (21 тест, 256 проверок). Pint — успешно, `check lint` зелёный.
- UI нет; скриншоты неприменимы.

## Техдолг
- Различение симуляции и настоящего job-первого-хода по префиксу `idempotency_key` (`sim:`)
  хрупкое: совпадение префикса у реального job-ключа сломает авторизацию. Надёжнее — отдельная
  колонка происхождения claim.

## Принято по умолчанию
- Переиспользовали уже source-агностичную «Api»-цепочку (`ApiFollowupInput`/`FollowupCoordinator`/
  `FollowupFinalizer`), а не дублировали HTTP-сессионную `Protected*`-инфраструктуру (c02_*-таблицы):
  она уже обслуживает первый ход для `public`/`token`-источников (baseVersion=0), добавление job
  было совместимым расширением без рефакторинга, как разрешено решением владельца 06.11.
- Приветствие (GreetingResolver) для job-источника не добавляется — как и для `token`, это уже
  существующая асимметрия `FollowupFinalizer`, а не новое решение.
