# SP-F-02 · Примитивы ДС

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: shadcn-основа + компоненты из 06 §3 со всеми состояниями (единые Tabs/StepProgress); Storybook-lite (страница `/dev/ds` в local)

Зависимости (нужен done после merge): SP-F-01.
Область записи: docs/design/components.md, resources/js/components/ui/, resources/js/pages/Dev/, routes/features/F-02.php, tests/Browser/DesignSystem/, docs/results/SP-F-02.md.
Экраны: инфраструктура/контракты.
Источники: F-02.
- F-02: 06 §3, ТЗ §1.4; R-S-06, R-V-08

Приёмка:
- vitest на каждый компонент (состояния, a11y-роли)

Дополнительно: Сохранять поведение донора; изменения только по принятым решениям.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-F-02.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
