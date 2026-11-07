

## Остаток 07.11.2026 (после слияния 21a07543…32c69c22)
- `app/ProtectedOperations/DraftSimulation/FixtureRuntime.php::validateExtraction` отбрасывает slot_updates вне LeadState::SLOTS — принять опубликованные доп. поля, чтобы песочница `/seller/knowledge/sandbox/reply` подтверждала их заполнение через fixture-движок; выполнять после done SP-E5-03F.
- `resources/js/pages/Seller/Knowledge/Releases/Run.tsx`: показывать названия опубликованных доп. полей вместо сырого id, когда SP-E4-01X передаст их из ReleasesController::runScenarios.
- D-3.1 фильтры и D-3.2 карточка: построчная сверка с макетом сверх покрытия SP-E6-10C.


## Остаток 07.11.2026 19:00 (после слияния 3c195095)
- `app/ProtectedOperations/DraftSimulation/FixtureRuntime.php::validateExtraction`: принимать slot_updates по опубликованным доп. полям (SP-E5-03F done, файл свободен); тест в `tests/Feature/Seller/Engine/`, подтверждающий заполнение доп. поля через `/seller/knowledge/sandbox/reply` fixture-движком.
- `resources/js/pages/Seller/Knowledge/Releases/Run.tsx`: названия доп. полей вместо id — только если SP-E4-01X уже передаёт их из ReleasesController; иначе оставить и записать в отчёт.
- Scope сужен до этих файлов; Engine/Dialogs/Customers/Settings закрыты и освобождены для других карточек.
