# SP-E6-03L · Policy в защищённых ходах

**Зачем:** завершить D-5.6 по решению владельца.
**Часть чего:** остаток SP-E6-03C, docs/results/SP-E6-03C.md.
**Зависимости:** SP-E6-03C в done.

Подключить Policy опубликованного снимка к защищённому follow-up и финализатору первого хода, включая handoff при пороге сообщений вне темы и правила негатива. Проверить runtime, изоляцию брендов и fake AI, сохраняя прежний spam по умолчанию.

Scope: `app/ProtectedOperations/`, `app/Seller/Engine/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/Seller/Engine/`, `tests/Browser/E6-03L/`, `docs/results/SP-E6-03L.md`.

Приёмка: основной сценарий, права, изоляция брендов и ошибки; только fake внешние вызовы. Остаток описать в docs/results/SP-E6-03L.md.
