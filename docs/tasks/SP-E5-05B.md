# SP-E5-05B · Назначение менеджера и переход к диалогу клиента

Размер: 1–3 часа. Функциональный остаток; новой возможности сверх ТЗ нет.

**Зачем:** закрыть D-3.2, макет 45 после частичной сдачи SP-E5-05 и дать исполнителям независимую работу по экрану или сценарию ТЗ.

**Часть чего:** SP-E5-05. После карточки создание напоминаний остаётся в SP-E5-05C; списки, задачи и заметки уже в SP-E5-05.

Зависимости: SP-E5-05, SP-E5-04.

Доделать смену менеджера клиента с проверкой прав и бренда; связать карточку с существующим экраном диалога. Проверить отсутствие перехода к чужому бренду.

Scope: `app/Seller/Customers/CustomersController.php`, `resources/js/pages/Seller/Customers/Show.tsx`, `resources/js/pages/Seller/Customers/Index.tsx`, `routes/features/E5-05.php`, `tests/Feature/Seller/Customers/CustomersTest.php`, `tests/Feature/Seller/Customers/AssignmentTest.php`, `docs/results/SP-E5-05B.md`. Подкаталог `ImportExport/` принадлежит SP-E5-08; чужие модули и golden fixtures не менять.

Приёмка: сценарий, права и изоляция бренда, 1–2 ошибки ввода; только fake/HTTP fixtures. Отчёт `docs/results/SP-E5-05B.md`, submit и зелёный CI до merge.
