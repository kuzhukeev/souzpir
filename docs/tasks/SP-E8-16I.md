# SP-E8-16I · Замер: страница и прогресс (продолжение SP-E8-13).

**Зачем:** решение владельца от 05.11.2026; переаудит 56 экранов.
**Часть чего:** остаток требований экрана/задачи, перечисленных ниже.
**Зависимости:** SP-E8-13.

## Приёмка
- V-3.3: маршрут /vision/runs/{id}, «осталось ~N минут», «Уведомить» о завершении, лента событий, число уточнений в баннере.
- V-3.4: шапка «Замер № N · M модели · K прогона», цветная легенда.
- V-4.4: «Видимость X % → Y %».
- RunProgress.tsx: детали только у активного замера.
- Пагинация уточнений сверх 50 замеров.

Без боевых вызовов: каналы за выключенным флагом, тесты на fake. Сверить экран с макетом, проверить права, бренд и ошибки. Остаток записать в отчёт.

Scope: `app/Vision/Core/`, `app/Vision/Measurements/`, `resources/js/pages/Vision/`, `routes/features/E8-13.php`, `tests/Feature/Owner/E8-16I/`, `tests/Browser/E8-16I/`, `docs/results/SP-E8-16I.md`, `app/Vision/Clarifications/`, `app/Vision/Reports/`, `resources/js/features/Vision/Measurements/`, `tests/Feature/Vision/Measurements/`, `tests/Feature/Vision/Clarifications/`.
