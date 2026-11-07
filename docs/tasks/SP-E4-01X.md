# SP-E4-01X · Protected-адаптер и частичные результаты

**Зачем:** завершить требования по решению владельца.
**Часть чего:** остаток SP-E4-01W, docs/results/SP-E4-01W.md.
**Зависимости:** SP-E4-01W.

## Приёмка
- Подключить draft-preview к общим защищённым first-turn стадиям без подмены опубликованного релиза.
- Применить каталоги R/S по полному доказанному контексту и событиям; показать частично неизвестные результаты в экране.
- Сохранить session-bound fallback до готовности protected-адаптера.

Остаток записать в отчёт.

Scope: `app/ProtectedOperations/`, `app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/Seller/Evaluation/`, `resources/js/pages/Seller/Knowledge/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/Seller/Evaluation/`, `tests/Browser/E4-01X/`, `docs/results/SP-E4-01X.md`.

## Решение владельца 06.11.2026 — общие AI-стадии

Решение владельца 06.11: разрешены только совместимые добавления общих AI-стадий без рефакторинга и без изменения существующего поведения и записанного replay; проверить права, изоляцию брендов и повторы. Только fake AI; живые и платные вызовы выключены. Общий ресурс shared-ai-stages занимает одна карточка до слияния. Прежние ограничения «до решения владельца» считать снятыми в этих границах.

## Порядок работы 07.11.2026
- Дождаться SP-E5-10D: обе задачи изменяют `resources/js/pages/Seller/Knowledge/Releases/Index.tsx`; это сохраняет независимое владение файлом. Объём приёмки не сокращён.

## Конкретный остаток SP-E4-01AA
- D-4.12: показывать число диалогов и качество по версии только по достоверной связи релиза с защищёнными стадиями и оценками; для старых данных без связи сохранять «—», не приписывать их текущей версии.
- D-4.12: автор и время строк diff — только по однозначной связи с аудитом конкретного изменения; при отсутствии подтверждения сохранять «—», не использовать автора публикации как автора всех изменений.

Дополнительный scope: `app/Seller/Knowledge/Releases/`, `tests/Feature/Seller/Knowledge/`.

## Конкретные расхождения SP-E9-01F
- D-4.1/D-4.2: исправить различимость двух ссылок «Открыть диалог» во входящих (axe link-in-text-block).
- D-4.12: добавить в окно публикации поясняющую строку и кнопку отмены по макету.

## Дополнение 07.11.2026 (из отчёта SP-E6-10E)
- `ReleasesController::runScenarios` передаёт в `Releases/Run.tsx` названия опубликованных дополнительных полей (client_fields из опубликованной policy), чтобы страница показывала их вместо сырого id.


## Уточнение scope 07.11.2026 18:30
- Вместо `app/ProtectedOperations/` и `app/Ai/Protected/` целиком в scope только файлы first-turn и DraftSimulation: `app/ProtectedOperations/DraftSimulation/`, `app/ProtectedOperations/FirstSellerTurnController.php`, `app/ProtectedOperations/FirstTurnCoordinator.php`, `app/ProtectedOperations/FirstTurnDeterministicGuard.php`, `app/ProtectedOperations/FirstTurnGoal.php`, `app/ProtectedOperations/FirstTurnProtectedClaim.php`, `app/ProtectedOperations/FirstTurnState.php`, `app/ProtectedOperations/FirstTurnTextFinalizer.php`, `app/ProtectedOperations/JobFirstTurnClaim.php`, `app/ProtectedOperations/JobFirstTurnInput.php`; `app/Ai/Protected/ApiOpeningInput.php`, `app/Ai/Protected/DraftResponder.php`, `app/Ai/Protected/GuardProjection.php`, `app/Ai/Protected/ProtectedRetryContext.php`, `app/Ai/Protected/RawToolArguments.php`, `app/Ai/Protected/RequestFingerprint.php`, `app/Ai/Protected/ResponderProjection.php`, `app/Ai/Protected/SellerExtractorStages.php`, `app/Ai/Protected/SellerGuardStages.php`, `app/Ai/Protected/SellerResponderStages.php`, `app/Ai/Protected/SellerSummaryStages.php`. `ApiFollowupInput.php` и `SellerFollowup/` заняты SP-E5-02C — не трогать. `FixtureRuntime.php` до done SP-E6-10E не менять.

- 07.11 20:00: показ названий доп. полей в `resources/js/pages/Seller/Knowledge/Releases/Run.tsx` (вместо сырого id) выполняется в этой карточке вместе с передачей их из ReleasesController — SP-E6-10E закрыта, Run.tsx входит в scope `resources/js/pages/Seller/Knowledge/`.

## Уточнение scope 07.11.2026 22:50
- `app/ProtectedOperations/FirstTurnTextFinalizer.php` занят SP-E7-04M и исключён из scope; если правка финализатора нужна — пункт в «Нужно от интегратора».
