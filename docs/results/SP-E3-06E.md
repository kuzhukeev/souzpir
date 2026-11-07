Модель: Sonnet 5

# SP-E3-06E — первый защищённый ход симуляции (сужена 07.11)

По уточнению интегратора 07.11.2026: первый клиентский ход уже проходит защищённые extractor/guard/responder через `FollowupCoordinator::runJob` (вызывается из `ProtectedSimulationRuntime::turn`); отдельный `FirstTurnCoordinator` не нужен, контракт `JobFirstTurnClaim`/06D-06K не менялся. `app/ProtectedOperations/` и `app/Seller/Evaluation/` — без изменений кода, задача закрыта тестами.

- Проверено: первое клиентское сообщение проходит защищённые стадии с fake AI — `ProtectedSimulationRuntimeTest::test_job_runs_persona_through_protected_fake_conversation` (сквозной job → `ProtectedSimulationRuntime`, без подмены раннера).
- Проверено: повтор того же сообщения идемпотентен — `test_job_entrypoint_checks_brand_key_and_input`, `test_confirmed_fake_failures_can_be_retried_without_duplicate_messages` (повторный `turn()` с тем же ключом не дублирует сообщения).
- Проверено: чужой бренд/организация не видит и не запускает ход — `IdempotencyFailure` при другом brand_id в тех же тестах; `SimulationBridgeE306DTest` на уровне job.
- Проверено: ошибка Gateway не ломает экран симуляции — новый тест `test_job_run_fails_closed_without_crashing_the_screen_on_a_gateway_rejection`: сквозной `SimulateFakeJob::dispatchSync` с AI-драйвером, всегда возвращающим `Rejected`, на первом ходе; прогон не бросает исключение наружу, `seller_sim_runs.status = failed`, запись в `audit_logs`, сообщения не задублированы, другой бренд не затронут.
- Тесты: `php artisan test tests/Feature/Seller/Evaluation/` — 29/29 зелёные (373 проверки), включая новый тест и все существующие в модуле (регрессия не внесена). Pint — чисто.

## Остаток
Нет — сужение 07.11 закрывает карточку этим набором тестов без изменения кода.

## Техдолг
Нет новых пунктов.

## Принято по умолчанию
Класс исключения, попадающий в `metrics.error` при отказе Gateway на первом ходе — `LogicException` (ограниченная регенерация `prepareFinalRound` конвертирует исходный `Rejected` в неё после исчерпания попыток), а не исходный `Rejected`; тест и отчёт фиксируют фактическое поведение кода.
