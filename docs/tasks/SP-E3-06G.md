# SP-E3-06G · Защищённый job-claim и AI-стадии первого хода

**Зачем:** разблокировать первый защищённый ход симуляции по решению владельца от 05.11.2026.
**Часть чего:** остаток SP-E3-06F, docs/results/SP-E3-06F.md.
**Зависимости:** SP-E3-06F в done.

Реализовать job-claim с миграцией и авторизацией без поддельной HTTP-сессии; подключить общие extractor, responder, guard и FirstTurnCoordinator к сохранённому job-входу. Сохранить HTTP-права и проверки организации и бренда, проверить полный fake-ход на изолированной PostgreSQL.

Scope: `app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/ProtectedOperations/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/Ai/`, `tests/Unit/Ai/`, `docs/results/SP-E3-06G.md`, `app/Shell/Auth/`, `database/migrations/E3-06G/`, `app/Tenancy/TenantContext.php`, `app/Tenancy/TenantContextRunner.php`.

Приёмка: job и HTTP сценарии, права, бренд, ошибки и только fake AI. Остаток записать в docs/results/SP-E3-06G.md.
