# SP-T-02 · Эталонный релиз знаний

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Импортер YAML → `knowledge_releases` и `KnowledgeEquivalenceTest`

Зависимости (нужен done после merge): SP-E0-05, SP-E3-01.
Область записи: app/Seller/Knowledge/Import/, docs/baseline/knowledge.md, tests/Feature/KnowledgeEquivalence/, tests/fixtures/knowledge/, docs/results/SP-T-02.md.
Экраны: инфраструктура/контракты.
Источники: T-02.
- T-02: 04 §6.1, 07 §1

Приёмка:
- массив snapshot == `Knowledge::all()`; экспорт → diff только порядок ключей

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-T-02.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
