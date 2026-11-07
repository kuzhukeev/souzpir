Модель: Claude Sonnet 5

# SP-E3-10C

Сделано: защищённый summary (`SellerSummaryStages`) подключён к `BrandPromptSets` — при опубликованных знаниях версию выбирает персона (v1 только для «Союз Пир», иначе v2), при отсутствии знаний (`KnowledgeNotReady`) поведение не изменилось — остаётся глобальный v1, как и раньше. Для simulator-стадии (у которой загрузчика не было вообще) добавлен минимальный действующий вход: `RuntimeSimulation::run()` резолвит версию через `BrandPromptSets::load(..., 'simulator')` при наличии знаний (иначе v1) и пишет её в `metrics.prompt_version`; содержимое реплик клиента остаётся прежним (детерминированный fake, без живых AI-вызовов).

Тесты (проверено):
- `php artisan test tests/Feature/Ai/ProtectedSummaryStageTest.php` — 13/13 (105 проверок), включая новый тест изоляции другого бренда (v2, нет «пиротех»/«Союз Пир», отдельная строка prompt_sets).
- `php artisan test tests/Feature/Seller/Evaluation/RuntimeSimulationTest.php` — 3/3 (16 проверок), включая новый тест v1↔v2 по знаниям и изоляции бренда в `prompt_sets`.
- `php artisan test tests/Feature/Seller/Engine/BrandPromptSetsE310BTest.php tests/Unit/Seller/Engine/SummaryPrototypeGoldenTest.php` — golden v1 не трогались, 6/6 без изменений.
- Смежные тесты simulator/judge (`SimulationReleaseTest`, `SandboxReplayE409CTest`, `SimulationBridgeE306DTest`, `ProtectedSimulationRuntimeTest`, `FakeSimulationTest`, `JudgeSupervisorTest`, `SavedScenarioE408KTest`, `E702DTest`) — без регрессий.
- `./vendor/bin/pint --test` — пройден.
- Полный прогон `tests/Feature/Ai/`, `tests/Feature/Seller/Engine/`, `tests/Feature/Seller/Evaluation/`, `tests/Unit/Seller/Engine/` — 252/280 (28 падений воспроизводятся идентично и на коде без моих правок: `CreatePromiseTest`/`ExtractorGatewayIntegrationTest`/`ProtectedExtractorStageTest`/OpenRouter — «An isolated test database is required», роль тестовой БД этого слота имеет createdb/superuser; проверено откатом правок через именованный `git stash` — падения те же).

Остаток: Judge-стадия (`Judge::request()`) не подключена к `judge.blade.php`/v2 — этот шаблон требует `issueTypes/facts/cond/card/outcome/tasks`, которых нет ни в контексте симуляции персон (`Judge::evaluate()`), ни в контексте `ConversationFakeJudge`; подстановка пустых значений дала бы некорректный промпт. Нужно отдельное решение по источнику этих данных.

## Техдолг
Судейский (judge) и ответчик (responder) прототипные стадии не версионируются по бренду вообще — только summary/extractor/guard/simulator.

## Принято по умолчанию
Для simulator-стадии версия фиксируется только в metrics прогона (аудируемость/изоляция), содержимое реплик клиента не меняется — живых AI-вызовов не вводилось.

## Нужно от интегратора
Решить, нужен ли отдельный источник данных (facts/cond/card/outcome) для рендера judge.blade.php v1/v2, или Judge остаётся на упрощённом хардкод-промпте.
