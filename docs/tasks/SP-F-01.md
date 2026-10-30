# SP-F-01 · Токены и темы

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: `@theme`, 3 темы, шрифт Manrope, пиксельная сверка цветов с папкой 13

Зависимости (нужен done после merge): SP-E0-01.
Область записи: docs/design/tokens.md, public/fonts/, resources/css/themes/, tests/Unit/ThemeTokens/, docs/results/SP-F-01.md.
Экраны: инфраструктура/контракты.
Источники: F-01.
- F-01: 06 §1, ТЗ §1.2–1.3, `13/…S-1.2-attention`

Приёмка:
- контраст ≥ 4.5:1 для всех тем (тест vitest на токены)

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-F-01.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
