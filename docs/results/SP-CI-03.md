# SP-CI-03 — профиль Core
## Что сделано
- Команда `php artisan test --parallel --without-databases --processes=4 --exclude-group=race --testsuite=Core --profile` выявила ограничение ParaTest: он не принимает `--profile`. Отдельный тест с `--profile` прошёл за 9,86 с; полный JUnit-профиль получен тем же parallel-запуском с `--log-junit`; 827 кейсов, 2575,6 с суммарного времени тестов, 2063,7 с wall time на Windows.
- Локальный Windows-прогон дал 6 ошибок окружения/порядка; результатом о зелёности служит CI Linux main #436. Времена ниже — для поиска узких мест, не прогноз длительности CI.
- FirstTurnStateTest: 86 кейсов, 2012,2 с (78% времени Core); следующие классы: ProtectedExtractorStageTest 166,1 с, GatewayTest 97,5 с, ManualReviewRequestsHttpTest 48,9 с, ProtectedSummaryStageTest 37,5 с.

## Топ-10 кейсов (Windows JUnit)
1. Model guard unknown tool calls and malformed result are fail closed — 174,14 с.
2. First text never saves unknown or failed guard or tool signal — 83,61 с.
3. Coordinator runs required model guard and blocks required effect — 72,45 с.
4. Guard binding migration preserves unsigned legacy history without trusting it — 71,41 с.
5. Model guard rejects forgery tampering revocation and new input — 66,67 с.
6. First text waits for required model guard and rejects effects — 58,06 с.
7. Cleared guard binding cannot reseal an old call — 51,02 с.
8. Model guard requires server enablement and binds confirmed sources — 47,71 с.
9. Model guard confirmed driver error is not retried — 45,97 с.
10. Model guard binding seal rejects privileged replacement with another valid result — 45,11 с.

## Решение и миграции
- Все 10 — FirstTurnStateTest; вынести его из общего Core и распределить по отдельным CI-группам в SP-CI-04. Остальные перечисленные классы оставить в лёгком Core, без изменения проверок.
- `migrate:fresh` на изолированной PostgreSQL БД — 1850,75 мс. Самые долгие миграции: e3_04_create_seller_conversation_tables 181,59 мс; e3_01_create_knowledge_tables 176,44 мс; create_seller_notification_rules 91,57 мс; create_vision_core_tables 90,07 мс; create_vision_profile_tables 86,65 мс. Каждая <200 мс, оставить; изменение схемы ради этих миллисекунд не оправдано.
- Исходная точка Linux CI #410 (ac156ac0): самый долгий job Core 749 с, весь прогон 753 с.

## Выполненная проверка
- JUnit-файл `.local/core-junit-20261102.xml` из изолированного worktree SP-CI-01; БД `souzpir_test_ci01` и отдельные worker БД. Источник не коммитится.
## Техдолг
- Нет.
## Принято по умолчанию
- Профилирование под ParaTest ведётся через JUnit timing, поскольку `--profile` не поддерживается его CLI.
