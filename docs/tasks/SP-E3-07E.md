# SP-E3-07E · Replay: проверка нового runtime

**Зачем:** завершение экранов по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E3-07D, docs/results/SP-E3-07D.md.
**Зависимости:** SP-E3-07D.

## Приёмка
- Подключить записанный адаптер к новому runtime и проверить 41 сценарий, follow-up, ошибки и изоляцию; включить runtime replay в CI, задокументировать подтверждённые расхождения без изменения golden fixtures.
- Проверить основной сценарий, права, изоляцию брендов, ошибки и макет; живые внешние вызовы отключены. Остаток оформить по AGENTS.md.

Scope: `app/ProtectedOperations/`, `app/Seller/Runtime/`, `tests/Equivalence/`, `docs/validation/`, `docs/sources/09_questions_assumptions_decisions.md`, `.github/workflows/`, `docs/results/SP-E3-07E.md`.
