# SP-E3-06H · Подключить job к защищённым AI-стадиям

**Зачем:** завершить требования по решению владельца.
**Часть чего:** остаток SP-E3-06G, docs/results/SP-E3-06G.md.
**Зависимости:** SP-E3-06G.

## Приёмка
- Подключить job-claim к FirstTurnCoordinator, extractor, responder, guard и финализации без HTTP-сессии.
- Адаптировать проверки происхождения; восстановление после прерывания, полный fake-ход, HTTP-права и ошибки.
- До реализации не подключать job-claim к очереди.

Остаток записать в отчёт.

Scope: `app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/ProtectedOperations/`, `app/Seller/Conversations/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/Ai/`, `tests/Unit/Ai/`, `docs/results/SP-E3-06H.md`.
