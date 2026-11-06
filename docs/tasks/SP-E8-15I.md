# SP-E8-15I · Точный календарный расчёт измерений

**Зачем:** завершить расчёт периода на экранах V-11.2 и V-11.3.
**Часть чего:** ограничение SP-E8-15H, `docs/results/SP-E8-15H.md`.
**Зависимости:** SP-E8-15H в `done`, подтверждённая владельцем формула.

## Приёмка
- После утверждения формулы реализовать точный расчёт для календарного месяца и выбранного периода; до решения не выдумывать тарифы и сохранять существующую оценку.
- Проверить границы периодов, отсутствие данных, права и изоляцию брендов; только fake runtime.

Scope: `app/Vision/Measurements/`, `app/Vision/Settings/`, `resources/js/pages/Vision/Measurements/`, `resources/js/pages/Vision/Settings/`, `tests/Feature/Vision/Settings/`, `tests/Browser/E8-15I/`, `docs/results/SP-E8-15I.md`.
