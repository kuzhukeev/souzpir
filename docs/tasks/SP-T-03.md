# SP-T-03 · Запись и проверка полного golden baseline

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Операторский этап: записать реальные ответы для missing кейсов T-01 только после предоставления рабочего доступа и бюджета, либо импортировать уже существующие записи. Нельзя подменять результат synthetic. До реального разрешения/доступа задача блокируется, остальные ветви продолжаются.

Зависимости (нужен done после merge): SP-E0-05, SP-T-01.
Область записи: docs/baseline/, tests/fixtures/prototype/, tools/baseline/, docs/results/SP-T-03.md.
Экраны: инфраструктура/контракты.
Источники: T-01.
- T-01: 08 §2.1; proto `AgentSimulate`, `tools/personas.yaml`, `Orchestrator`

Приёмка:
- Все 41 кейс имеют provenance, seed/config/model/prompt versions; повторный replay детерминирован и offline.

Дополнительно: Внешнее условие: реальные LLM-записи/доступ и бюджет. Никогда не печатать ключи.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-T-03.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
