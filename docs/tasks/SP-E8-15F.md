# SP-E8-15F · Слоты на экранах (продолжение SP-E8-15E/16H).

**Зачем:** решение владельца от 05.11.2026; переаудит 56 экранов.
**Часть чего:** остаток требований экрана/задачи, перечисленных ниже.
**Зависимости:** SP-E1-13, SP-E8-15E, SP-E8-16H.

## Приёмка
- V-2.1: «× N моделей × 1 прогон/нед».
- V-2.3: «Будет списано N слотов в неделю».
- V-3.2: оценка времени из прошлого замера вместо «20–40 минут», один контрол числа прогонов.
- V-11.1: описания моделей из конфига провайдера, «Стоимость: N слотов».
- V-11.2: расчёт за месяц.
- V-11.3: формула за период, «Увеличить лимит» ведёт в S-4.3.
- Двойная строка плана ответов в Questions/Workspace.tsx:457,459.

Без боевых вызовов: каналы за выключенным флагом, тесты на fake. Сверить экран с макетом, проверить права, бренд и ошибки. Остаток записать в отчёт.

Scope: `app/Vision/Questions/`, `app/Vision/Measurements/`, `app/Vision/Settings/`, `resources/js/pages/Vision/Questions/`, `resources/js/pages/Vision/Measurements/`, `resources/js/pages/Vision/Settings/`, `tests/Feature/Owner/E8-15F/`, `tests/Browser/E8-15F/`, `docs/results/SP-E8-15F.md`, `resources/js/features/Vision/Questions/`, `resources/js/features/Vision/Measurements/`, `tests/Feature/Vision/Questions/`, `tests/Feature/Vision/Settings/`.

Дополнительный точечный scope для CI: `tests/Browser/E8-15E/weekly.spec.ts` — актуализировать ожидания по расчёту слотов, сохраняя смысл проверок.
