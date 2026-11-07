# SP-E1-12E · Оболочка: мелкие дефекты.

**Зачем:** решение владельца от 05.11.2026; переаудит 56 экранов.
**Часть чего:** остаток требований экрана/задачи, перечисленных ниже.
**Зависимости:** нет.

## Приёмка
- Тип StatusData (types/Shell/Support/index.ts:85), убрать каст в Status.tsx:64.
- Логотип бренда везде PNG/SVG до 1 МБ (ThemeForm.tsx:115, BrandSettingsController.php:101,110, BrandSettings.php:189-191).
- «Доступно компаний» из count (OrganizationSelect.tsx:30).

Без боевых вызовов: каналы за выключенным флагом, тесты на fake. Сверить экран с макетом, проверить права, бренд и ошибки. Остаток записать в отчёт.

Scope: `app/Shell/Brands/`, `app/Shell/Organization/`, `resources/js/pages/Shell/`, `resources/js/types/Shell/`, `tests/Feature/Owner/E1-12E/`, `tests/Browser/E1-12E/`, `docs/results/SP-E1-12E.md`, `app/Shell/Support/`, `app/Shell/Organizations/`, `resources/js/features/Shell/Brands/`, `tests/Feature/Shell/Brands/`, `tests/Feature/Shell/Support/`.
