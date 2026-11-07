# SP-E8-16L · Прогресс замера и динамика отчёта

**Зачем:** завершить экран по принятому результату.
**Часть чего:** остаток SP-E8-16K, docs/results/SP-E8-16K.md.
**Зависимости:** SP-E8-16K.

## Приёмка
- Реализовать отдельный маршрут прогресса, оставшееся время, уведомление, ленту и число уточнений на V-3.3; разместить подтверждённую динамику видимости на V-4.4 и сверить с макетами.
- Проверить права, изоляцию брендов и ошибки; незавершённое указать в «Остаток».

Scope: `app/Vision/Measurements/`, `app/Vision/Reports/`, `routes/features/`, `resources/js/pages/Vision/Measurements/`, `resources/js/pages/Vision/Reports/`, `tests/Feature/Vision/Measurements/`, `tests/Feature/Vision/Reports/`, `tests/Browser/E8-16L/`, `docs/results/SP-E8-16L.md`.

## Остаток 07.11.2026 (аудит экранов, интегратор)
- V-4.4: на шаге «Готово» мастера уточнений — строка «Отчёт пересчитан… Видимость X % → Y %» по фактическому пересчёту; сохранённый снимок отчёта пересчитывается после уточнений без перезаписи исходника (по образцу пересчёта V-6.3); тесты прав и бренда.
- Дополнительный scope: `app/Vision/Clarifications/`, `resources/js/pages/Vision/Clarifications/`, `tests/Feature/Vision/Clarifications/`.

## Уточнение scope 07.11.2026 23:55 (планировщик)
- `routes/features/` заменён на новый файл `routes/features/E8-16L.php` (FeatureRegistry подхватывает каталог); существующие файлы маршрутов не править — новые маршруты только в этом файле.
