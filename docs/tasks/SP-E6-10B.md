# SP-E6-10B · Поля клиента: извлечение. DialogReader, Extractor, LeadState и PRIORITY_SLOTS берут поля из опубликованной версии бренда.

**Зачем:** решение владельца от 05.11.2026; переаудит 56 экранов.
**Часть чего:** остаток требований экрана/задачи, перечисленных ниже.
**Зависимости:** SP-E6-10, SP-E3-10C.

## Приёмка
- Поля клиента: извлечение. DialogReader, Extractor, LeadState и PRIORITY_SLOTS берут поля из опубликованной версии бренда.

Без боевых вызовов: каналы за выключенным флагом, тесты на fake. Сверить экран с макетом, проверить права, бренд и ошибки. Остаток записать в отчёт.

Scope: `app/Seller/Engine/`, `app/Seller/Runtime/`, `app/Seller/Knowledge/`, `tests/Feature/Owner/E6-10B/`, `tests/Browser/E6-10B/`, `docs/results/SP-E6-10B.md`, `app/Seller/Dialogs/DialogReader.php`, `tests/Feature/Seller/Engine/`, `tests/Feature/Seller/Runtime/`.
