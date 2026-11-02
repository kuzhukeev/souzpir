# SP-E5-05C · Создание напоминаний из карточки клиента

Размер: 1–3 часа. Функциональный остаток; новой возможности сверх ТЗ нет.

**Зачем:** закрыть D-3.2–D-3.3, макеты 45–46 после частичной сдачи SP-E5-05 и дать исполнителям независимую работу по экрану или сценарию ТЗ.

**Часть чего:** SP-E5-05. После карточки остатка SP-E5-05 для D-3.2/D-3.3 нет.

Зависимости: SP-E5-05B.

Добавить создание напоминания с привязкой к клиенту и менеджеру, валидировать срок и права, показать в задачах и карточке; использовать существующий outbox fake для срока.

Клиент без диалога тоже может получить напоминание. Для nullable `conversation_id` и прямой привязки к `contact_id` интегратор разрешил узкую миграцию `database/migrations/E5-05C/` через `scripts/task.py`.

Scope: `app/Seller/Customers/CustomersController.php`, `app/Seller/Customers/NotifyDuePromises.php`, `app/Seller/Customers/PromiseSchedule.php`, `app/Seller/Customers/Reminders/`, `resources/js/pages/Seller/Customers/Show.tsx`, `resources/js/pages/Seller/Customers/Tasks.tsx`, `routes/features/E5-05.php`, `tests/Feature/Seller/Customers/CustomersTest.php`, `tests/Feature/Seller/Customers/Reminders/`, `docs/results/SP-E5-05C.md`. Импорт/экспорт SP-E5-08 в отдельной папке; чужие модули и golden fixtures не менять.

Приёмка: сценарий, права и изоляция бренда, 1–2 ошибки ввода; только fake/HTTP fixtures. Отчёт `docs/results/SP-E5-05C.md`, submit и зелёный CI до merge.
