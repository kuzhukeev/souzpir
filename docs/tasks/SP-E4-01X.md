

## Дополнение 07.11.2026 (из отчёта SP-E6-10E)
- `ReleasesController::runScenarios` передаёт в `Releases/Run.tsx` названия опубликованных дополнительных полей (client_fields из опубликованной policy), чтобы страница показывала их вместо сырого id.


## Уточнение scope 07.11.2026 18:30
- Вместо `app/ProtectedOperations/` и `app/Ai/Protected/` целиком в scope только файлы first-turn и DraftSimulation: `app/ProtectedOperations/DraftSimulation/`, `app/ProtectedOperations/FirstSellerTurnController.php`, `app/ProtectedOperations/FirstTurnCoordinator.php`, `app/ProtectedOperations/FirstTurnDeterministicGuard.php`, `app/ProtectedOperations/FirstTurnGoal.php`, `app/ProtectedOperations/FirstTurnProtectedClaim.php`, `app/ProtectedOperations/FirstTurnState.php`, `app/ProtectedOperations/FirstTurnTextFinalizer.php`, `app/ProtectedOperations/JobFirstTurnClaim.php`, `app/ProtectedOperations/JobFirstTurnInput.php`; `app/Ai/Protected/ApiOpeningInput.php`, `app/Ai/Protected/DraftResponder.php`, `app/Ai/Protected/GuardProjection.php`, `app/Ai/Protected/ProtectedRetryContext.php`, `app/Ai/Protected/RawToolArguments.php`, `app/Ai/Protected/RequestFingerprint.php`, `app/Ai/Protected/ResponderProjection.php`, `app/Ai/Protected/SellerExtractorStages.php`, `app/Ai/Protected/SellerGuardStages.php`, `app/Ai/Protected/SellerResponderStages.php`, `app/Ai/Protected/SellerSummaryStages.php`. `ApiFollowupInput.php` и `SellerFollowup/` заняты SP-E5-02C — не трогать. `FixtureRuntime.php` до done SP-E6-10E не менять.

- 07.11 20:00: показ названий доп. полей в `resources/js/pages/Seller/Knowledge/Releases/Run.tsx` (вместо сырого id) выполняется в этой карточке вместе с передачей их из ReleasesController — SP-E6-10E закрыта, Run.tsx входит в scope `resources/js/pages/Seller/Knowledge/`.
