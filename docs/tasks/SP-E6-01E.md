# SP-E6-01E · Подключить служебные фразы к ходу продавца

**Зачем:** завершить экраны D-6.3 по решению владельца от 03.11.2026.
**Часть чего:** остаток SP-E6-01D из docs/results/SP-E6-01D.md.
**Зависимости:** SP-E6-01D (дождаться merge).

Подключить ServicePhraseSelector к формированию и фиксации первого и последующих ответов на реальные события runtime; выполнить fake-прогон настоящего хода и проверить существующие fixtures.

Сверить с docs/sources/02b_mockup_journal.md; проверить сценарии, права, изоляцию брендов и ошибки. Без живых или платных вызовов в тестах.

Scope: `app/Seller/Runtime/`, `app/ProtectedOperations/`, `tests/Feature/Seller/Runtime/`, `tests/Feature/ProtectedOperations/`, `tests/Browser/E6-01E/`, `docs/results/SP-E6-01E.md`.

Отчёт: docs/results/SP-E6-01E.md; остаток указать по пунктам.
