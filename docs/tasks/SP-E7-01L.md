# SP-E7-01L · Качество: запуск fake-оценки диалога

**Зачем:** завершить D-7.2 и D-7.3 по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E7-01K, docs/results/SP-E7-01K.md.
**Зависимости:** SP-E7-01K в done.

Подключить защищённую fake-оценку реального диалога по кнопке; проверить сценарий, права, бренд и ошибки на изолированной БД. Старые связи без подтверждённого источника не угадывать. Общие AI-стадии без решения владельца не менять.

Scope: `app/Seller/Quality/`, `app/Seller/Evaluation/`, `app/Seller/Dialogs/`, `resources/js/pages/Seller/Quality/`, `resources/js/pages/Seller/Dialogs/`, `routes/features/E7-01.php`, `tests/Feature/Seller/Quality/`, `tests/Browser/E7-01L/`, `docs/results/SP-E7-01L.md`.

Приёмка: сценарий, права, бренд, ошибки; только fake вызовы. Остаток записать в docs/results/SP-E7-01L.md.
