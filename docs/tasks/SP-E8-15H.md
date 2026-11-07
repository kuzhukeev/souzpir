# SP-E8-15H · Управление прогонами и расчёт периода

**Зачем:** завершить экраны V-3.2, V-11.2 и V-11.3 по макетам.
**Часть чего:** остаток SP-E8-15G, `docs/results/SP-E8-15G.md`.
**Зависимости:** SP-E8-15G в `done`.

## Приёмка
- Сверить V-3.2: единый контрол прогонов и оценка времени по прошлому замеру; при отсутствии замера показывать отсутствие данных.
- Привести проверки `tests/Browser/E8-16/` и `tests/Browser/E8-16B/` к подтверждённому поведению без потери проверок. Описания моделей брать из конфигурации; отсутствующие тексты не выдумывать.
- Точный календарный расчёт V-11.2 и V-11.3 внедрять после утверждения формулы; до этого показывать существующую оценку с её ограничениями.
- Проверить права, изоляцию брендов, ошибки и основной сценарий; только fake runtime.

Scope: `app/Vision/Questions/`, `app/Vision/Measurements/`, `app/Vision/Settings/`, `resources/js/pages/Vision/Questions/`, `resources/js/pages/Vision/Measurements/`, `resources/js/pages/Vision/Settings/`, `resources/js/features/Vision/Questions/`, `resources/js/features/Vision/Measurements/`, `tests/Feature/Vision/Questions/`, `tests/Feature/Vision/Settings/`, `tests/Browser/E8-16/`, `tests/Browser/E8-16B/`, `tests/Browser/E8-15H/`, `docs/results/SP-E8-15H.md`.
