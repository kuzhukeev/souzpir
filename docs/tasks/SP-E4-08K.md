# SP-E4-08K · Песочница: неподтверждённая передача и персоны

**Зачем:** закрыть конкретный остаток приёмки SP-E4-08J и исходной функциональной задачи.
**Часть чего:** SP-E4-08J, docs/results/SP-E4-08J.md.
**Зависимости:** SP-E4-08J в done.

## Приёмка
- В песочнице подтвердить результат инструмента до показа передачи для wants_human, requisites и big_order; сохранить fallback и Guard без выдуманных действий. Включить вход requisites в шесть ходов без потери исходных фактов и проверить for_self_wedding и prices_only_rude. Golden fixtures и живые вызовы не менять.
- Проверить основной сценарий, права, ошибки и изоляцию бренда.

Scope: `app/Ai/Protected/`, `app/Seller/Evaluation/`, `app/Seller/Runtime/FirstInboundRuntime.php`, `app/ProtectedOperations/DraftSimulation/FixtureRuntime.php`, `tests/Feature/Seller/Evaluation/`, `tests/Browser/E4-08K/`, `docs/results/SP-E4-08K.md`.

## Остаток SP-E4-10Q
- D-4.11: выполнить сохранённый сценарий полностью по всем ходам и проверить изолированные fake-эффекты инструментов без записи в рабочие диалоги; сохранить честный fallback при отсутствии фикстур.
- Расширение scope: `app/Seller/Scenarios/ScenariosController.php`, `tests/Feature/Seller/Scenarios/`.
