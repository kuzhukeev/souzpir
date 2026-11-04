# SP-E6-04F · Передача человеку: runtime настроек

**Зачем:** завершить D-5.3 по решению владельца.
**Часть чего:** остаток SP-E6-04C, docs/results/SP-E6-04C.md.
**Зависимости:** SP-E6-04C в done.

Подключить сохранённые настройки уведомления, напоминания и запасной фразы к соответствующему runtime и открыть настройки распределения прямой ссылкой при наличии маршрута. Проверить текущий бренд, права, выключенные переключатели и fake-уведомления.

Scope: `app/Seller/Settings/Tools/`, `app/Seller/Runtime/`, `app/ProtectedOperations/`, `resources/js/pages/Seller/Settings/Tools/`, `tests/Feature/Seller/Settings/Tools/`, `tests/Browser/E6-04F/`, `docs/results/SP-E6-04F.md`.

Приёмка: основной сценарий, права, изоляция брендов и ошибки; только fake внешние вызовы. Остаток описать в docs/results/SP-E6-04F.md.
