# SP-E5-05E · Задачи менеджера: фильтры, drawer, «В работе»

**Зачем:** решение владельца от 03.11.2026 закрыть незапланированные расхождения с макетами по итогам аудита 160 экранов; экраны D-3.3.
**Часть чего:** остаток после SP-E5-05/05B/05C.
**Зависимости:** SP-E5-05D (дождаться merge).

- Экран D-3.3 «Задачи» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [46]): только фильтр «Срочно» (:11), нет mine/overdue в UI-переключателе (бэкенд :195); нет drawer задачи; нет статуса «В работе» (updateTask только new/done/cancelled :218). Где сейчас: Customers/Tasks.tsx:11; CustomersController.php:195,218.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Seller/Customers/`, `app/Seller/Tasks/`, `resources/js/pages/Seller/Customers/`, `resources/js/pages/Seller/Tasks/`, `routes/features/E5-05.php`, `tests/Feature/Seller/Customers/`, `tests/Feature/Seller/Tasks/`, `tests/Browser/E5-05E/`, `docs/results/SP-E5-05E.md`.

Отчёт: docs/results/SP-E5-05E.md; локальные тесты задачи, submit и зелёный CI.
