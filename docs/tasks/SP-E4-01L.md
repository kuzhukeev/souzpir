# SP-E4-01L · Защищённый прогон черновика

**Зачем:** завершить экран D-4.12 по решению владельца от 03.11.2026.
**Часть чего:** остаток SP-E4-01K из docs/results/SP-E4-01K.md.
**Зависимости:** SP-E4-01K (дождаться merge).

Завершить защищённый fake runtime для прогона черновика с извлечением намерений на каждом ходе и смысловую проверку произвольных критериев. Использовать существующие защищённые механизмы и подтверждённые данные; для доступа к черновику добавить отдельный внутренний fake-адаптер в разрешённой области; передавать проверенный контекст и идентификатор черновика, не подменять опубликованные знания и не запускать живые вызовы. Сверить экран с журналом макетов; проверить права, изоляцию бренда и ошибки. Без живых или платных вызовов в тестах.

Scope: `app/Seller/Knowledge/`, `app/Seller/Evaluation/`, `resources/js/pages/Seller/Knowledge/Releases/`, `routes/features/E4-01.php`, `tests/Feature/Seller/Knowledge/`, `tests/Browser/E4-01L/`, `docs/results/SP-E4-01L.md`, `app/ProtectedOperations/DraftSimulation/`, `app/Ai/Protected/`, `app/Ai/Gateway.php`, `tests/Feature/ProtectedOperations/DraftSimulation/`. Общий FirstTurnCoordinator сейчас занят другой задачей: не менять его до освобождения.

Отчёт: docs/results/SP-E4-01L.md; остаток указать по пунктам.
