# SP-E4-05C · Запреты: применение правил и срабатывания

**Зачем:** завершить D-4.9 по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E4-05B, docs/results/SP-E4-05B.md.
**Зависимости:** SP-E4-05B в done.

Подключить применение отключённых правил в детерминированном Guard, реальные срабатывания за 30 дней, подсветку совпавшего паттерна и настройку запрета канцелярита; сохранить golden fixtures и изоляцию брендов.

Scope: `app/Seller/Knowledge/Prohibitions/`, `resources/js/pages/Seller/Knowledge/Prohibitions/`, `routes/features/E4-05.php`, `tests/Feature/Seller/Knowledge/Prohibitions/`, `tests/Browser/E4-05C/`, `docs/results/SP-E4-05C.md`, `app/Seller/Engine/DeterministicGuard.php`, `app/Seller/Runtime/`, `tests/Feature/Seller/Runtime/`.

Приёмка: основной сценарий, права, бренд и ошибки; только fake внешние вызовы. Остаток записать в docs/results/SP-E4-05C.md.
