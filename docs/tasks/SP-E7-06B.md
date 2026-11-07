# SP-E7-06B · Продавец: видимые дефекты.

**Зачем:** решение владельца от 05.11.2026; переаудит 56 экранов.
**Часть чего:** остаток требований экрана/задачи, перечисленных ниже.
**Зависимости:** нет.

## Приёмка
- «Топ тем» названиями тем (DashboardQuery.php:208-216).
- LOAD_ERROR ловит не только QueryException (DashboardController.php:22-28).
- KPI скрыты без каналов.
- Фильтр «Канал» выбором.
- Ошибка песочницы по-русски (Sandbox/Index.tsx:8).
- Текст Calibration/Compare.tsx:10.
- «Проверить ошибку» в CRM скрыть вне тестов (Crm/Index.tsx:107).

Без боевых вызовов: каналы за выключенным флагом, тесты на fake. Сверить экран с макетом, проверить права, бренд и ошибки. Остаток записать в отчёт.

Scope: `app/Seller/Dashboard/`, `app/Seller/Sandbox/`, `resources/js/pages/Seller/Dashboard/`, `resources/js/pages/Seller/Sandbox/`, `resources/js/pages/Seller/Calibration/`, `resources/js/pages/Seller/Crm/`, `tests/Feature/Owner/E7-06B/`, `tests/Browser/E7-06B/`, `docs/results/SP-E7-06B.md`, `app/Seller/Analytics/`, `app/Seller/Calibration/`, `app/Seller/Integrations/`, `tests/Feature/Seller/Dashboard/`.
