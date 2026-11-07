# SP-E4-01G · Полный прогон сценариев и черновик отката

**Зачем:** завершить экран D-4.12 по решению владельца от 03.11.2026.
**Часть чего:** остаток SP-E4-01F из docs/results/SP-E4-01F.md.
**Зависимости:** SP-E4-01F (дождаться merge).

Проверять fake-ответы по ожиданиям сценариев, открыть конкретный прогон; реализовать откат в новый черновик с ясной подсказкой и обработать уже существующий черновик без потери данных.

Сверить с docs/sources/02b_mockup_journal.md; проверить основной сценарий, права, изоляцию брендов и ошибки. Без живых или платных вызовов.

Scope: `app/Seller/Knowledge/`, `app/Seller/Evaluation/`, `resources/js/pages/Seller/Knowledge/Releases/`, `routes/features/E4-01.php`, `tests/Feature/Seller/Knowledge/`, `tests/Browser/E4-01G/`, `docs/results/SP-E4-01G.md`.

Отчёт: docs/results/SP-E4-01G.md; незавершённые пункты перечислить в «Остаток».
