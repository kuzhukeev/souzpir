# SP-E4-05D · Запреты: счётчик срабатываний

**Зачем:** завершить D-4.9 по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E4-05C, docs/results/SP-E4-05C.md.
**Зависимости:** SP-E4-05C в done.

Записывать срабатывания Guard по правилам текущего бренда и показывать реальные количества за 30 дней. Сохранить текущую семантику Guard и не менять общие AI-стадии без решения владельца.

Scope: `app/ProtectedOperations/`, `app/Seller/Knowledge/Prohibitions/`, `resources/js/pages/Seller/Knowledge/Prohibitions/`, `database/migrations/E4-05D/`, `tests/Feature/Seller/Knowledge/Prohibitions/`, `tests/Feature/ProtectedOperations/`, `tests/Browser/E4-05D/`, `docs/results/SP-E4-05D.md`.

Приёмка: сценарий, права, бренд и ошибки; только fake вызовы. Остаток записать в docs/results/SP-E4-05D.md.
