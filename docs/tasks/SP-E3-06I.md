# SP-E3-06I · Полный защищённый fake-ход job

**Зачем:** завершить требования по решению владельца.
**Часть чего:** остаток SP-E3-06H, docs/results/SP-E3-06H.md.
**Зависимости:** SP-E3-06H.

## Приёмка
- Подключить job-claim к coordinator, extractor, responder, guard и финализации без HTTP-сессии.
- Проверить полный fake-ход, восстановление стадий, HTTP-права, бренд и ошибки.
- До готовности полного сценария не подключать job-claim к очереди.

Проверить права, бренд и ошибки; остаток записать в отчёт.

Scope: `app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/ProtectedOperations/`, `app/Seller/Conversations/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/Ai/`, `tests/Unit/Ai/`, `docs/results/SP-E3-06I.md`.
