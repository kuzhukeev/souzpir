# SP-CI-08 · Починить 11 оставшихся race-тестов

**Сверх ТЗ: прямое поручение владельца от 2026-11-02. Размер:** 1–3 часа на диагностику и тестовые исправления; реальные баги продукта выносить отдельно с высоким приоритетом.

**Зачем:** ночной `race` после подготовки изолированной БД в SP-CI-07 запускает 172 теста, но 11 падают; нужен достоверный зелёный прогон до live shadow.

**Часть чего:** продолжение SP-CI-07 после успешной подготовки роли и БД; эта карточка закрывает тестовую часть ночного job, дальше CI-07 не дробить.

Зависимости: SP-CI-07. Взять после текущей пачки задач по экранам ТЗ.

Scope: `.github/workflows/ci.yml`, `tests/Feature/`, `tests/Support/`, `scripts/ci/`, `docs/results/SP-CI-08.md`. Код приложения и golden fixtures вне scope.

Источник: [workflow_dispatch run 41412123385](https://github.com/kuzhukeev/souzpir/actions/runs/41412123385), job `race`, 11 failed / 3 skipped / 158 passed. Разобрать каждое падение:

- `Seller/Settings/Limits/SessionRoutingRaceTest` (несколько): `DatabaseMigrations` вызывает запрещённый `down()` E1-08; один сценарий сообщает `Expected lock wait not observed` и worker `Password reset failed`.
- `Shell/Profile/SecurityConcurrencyTest` (2): worker возвращает `failed`; получить точную ошибку worker, проверить наследование `DB_USERNAME`, `DB_DATABASE`, `DB_TEST_DATABASE`.
- `Shell/Data/ExportLogoutRaceTest` и `Tenancy/ConcurrencyTest`: запрещённый откат миграции E1-08.
- `ProtectedOperations/FirstTurnStateTest` (2): ожидает SQLSTATE `23514`, получает `0A000` при `TRUNCATE` под FK; явный `down()` `c02_responder_stages` блокируется зависимой `c02_responder_raw_evidence`.

Для каждого из 11 в отчёте дать таблицу «полное имя теста, причина а/б/в, что сделано»: **а** реальная гонка продукта — отдельная приоритетная карточка; **б** ожидание по времени / 1 vCPU — ждать проверяемое условие; **в** окружение и изоляция БД — исправить тестовую инфраструктуру или fixture. Не пропускать тесты и не ослаблять смысл проверок. После слияния SP-CI-07 и SP-CI-08 вручную выполнить `workflow_dispatch` на `main`, зафиксировать зелёный `race`.
