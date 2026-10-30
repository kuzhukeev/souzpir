# SP-T-01 · Эталон продавца: offline harness и сценарии

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Скопировать прототип в изолированную рабочую область, не менять .local/references. Подготовить recording/replay harness, manifest 29 персон + 12 явно перечисленных ручных кейсов, golden prompt snapshots и доступные deterministic fixtures. Не запускать платные LLM и не выдумывать записанные ответы. Отдельно отметить recorded, synthetic и missing; 100% эквивалентность допустима только на настоящем baseline. Команда оператора live capture — в SP-T-03.

Зависимости (нужен done после merge): нет.
Область записи: docs/baseline/, tests/fixtures/prototype/, tools/baseline/, docs/results/SP-T-01.md.
Экраны: инфраструктура/контракты.
Источники: T-01.
- T-01: 08 §2.1; proto `AgentSimulate`, `tools/personas.yaml`, `Orchestrator`

Приёмка:
- 31 исходный тест запускать на совместимом окружении; указать реальные результаты и блокеры.
- Replay не обращается в сеть; manifest содержит все 41 case IDs и происхождение данных; секреты редактируются до сохранения.

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-T-01.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
