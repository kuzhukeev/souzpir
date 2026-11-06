# SP-E4-08K · Песочница: неподтверждённая передача и персоны

**Зачем:** закрыть конкретный остаток приёмки SP-E4-08J и исходной функциональной задачи.
**Часть чего:** SP-E4-08J, docs/results/SP-E4-08J.md.
**Зависимости:** SP-E4-08J в done.

## Приёмка
- В песочнице подтвердить результат инструмента до показа передачи для wants_human, requisites и big_order; сохранить fallback и Guard без выдуманных действий. Включить вход requisites в шесть ходов без потери исходных фактов и проверить for_self_wedding и prices_only_rude. Golden fixtures и живые вызовы не менять.
- Проверить основной сценарий, права, ошибки и изоляцию бренда.

Scope: `app/Ai/Protected/`, `app/Seller/Evaluation/`, `app/Seller/Runtime/FirstInboundRuntime.php`, `app/ProtectedOperations/DraftSimulation/FixtureRuntime.php`, `tests/Feature/Seller/Evaluation/`, `tests/Browser/E4-08K/`, `docs/results/SP-E4-08K.md`.
