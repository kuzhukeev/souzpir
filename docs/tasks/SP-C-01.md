# SP-C-01 · Контракты и реестр владения до кода

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Зафиксировать публичные PHP/TS DTO и интерфейсы между Tenancy, Ai, KnowledgeSnapshot, SellerRuntime, Delivery, UsageReader, Audit, Notifications, Vision; маршруты, события, payload ошибок, idempotency и версии. Реестр таблиц/ID/FK, миграций и владельцев: одно имя kn_escalation, единый active-release pointer, единый источник текущего тарифа. Одна организация текущего кабинета, несколько брендов. Определить route/migration/config/i18n auto-discovery для изолированных feature-папок. Задать extension points для greetings, catalog/price, Guard severity и exporters: feature-настройки должны влиять на runtime через внедряемые adapters без правок чужого Engine. Не проектировать головную админку.

Зависимости (нужен done после merge): нет.
Область записи: docs/contracts/, docs/results/SP-C-01.md.
Экраны: инфраструктура/контракты.
Источники: аудит и решения пользователя.


Приёмка:
- Контракты содержат примеры payload и негативные случаи 403/404/409/422.
- Каждая таблица, shared file и событие имеют одного владельца; нет circular runtime dependencies.

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-C-01.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
