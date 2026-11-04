# SP-E3-06E · Первый защищённый ход симуляции

**Зачем:** завершить остаток по решению владельца 03.11.2026.
**Часть чего:** частичная сдача SP-E3-06D, отчёт docs/results/SP-E3-06D.md.
**Зависимости:** SP-E3-06D в done.

Подключить первое входящее сообщение симуляции через job-вход FirstTurnCoordinator и защищённые extractor/guard/responder с tenant/brand и идемпотентностью. Использовать fake AI, проверить чужой бренд и трассу первого хода.

Scope: `app/ProtectedOperations/`, `app/Seller/Evaluation/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/Seller/Evaluation/`, `docs/results/SP-E3-06E.md`.

Приёмка: соответствие указанным пунктам, права, бренд и ошибки; только fake внешние вызовы. Остаток и проверки записать в docs/results/SP-E3-06E.md.
