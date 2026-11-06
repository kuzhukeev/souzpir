# SP-E3-07F · Полный runtime replay

**Зачем:** закрыть конкретный остаток приёмки SP-E3-07E и исходной функциональной задачи.
**Часть чего:** SP-E3-07E, docs/results/SP-E3-07E.md.
**Зависимости:** SP-E3-07E в done.

## Приёмка
- Подключить записанные данные к runtime без изменения эталонных golden fixtures; проверить все 41 сценарий, follow-up, ошибки и изоляцию брендов; согласовать сопоставление запросов и зафиксировать фактические расхождения. Использовать fake, без живых вызовов.
- Проверить основной сценарий, права, ошибки и изоляцию бренда.

Scope: `app/Ai/Gateway.php`, `app/Seller/Runtime/`, `tests/Feature/Equivalence/Support/RecordedGateway.php`, `tests/Equivalence/`, `docs/validation/`, `docs/results/SP-E3-07F.md`.
