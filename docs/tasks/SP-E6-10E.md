

## Остаток 07.11.2026 (после слияния 21a07543…32c69c22)
- `app/ProtectedOperations/DraftSimulation/FixtureRuntime.php::validateExtraction` отбрасывает slot_updates вне LeadState::SLOTS — принять опубликованные доп. поля, чтобы песочница `/seller/knowledge/sandbox/reply` подтверждала их заполнение через fixture-движок; выполнять после done SP-E5-03F.
- `resources/js/pages/Seller/Knowledge/Releases/Run.tsx`: показывать названия опубликованных доп. полей вместо сырого id, когда SP-E4-01X передаст их из ReleasesController::runScenarios.
- D-3.1 фильтры и D-3.2 карточка: построчная сверка с макетом сверх покрытия SP-E6-10C.


## Остаток 07.11.2026 19:00 (после слияния 3c195095)
- `app/ProtectedOperations/DraftSimulation/FixtureRuntime.php::validateExtraction`: принимать slot_updates по опубликованным доп. полям (SP-E5-03F done, файл свободен); тест в `tests/Feature/Seller/Engine/`, подтверждающий заполнение доп. поля через `/seller/knowledge/sandbox/reply` fixture-движком.
- `resources/js/pages/Seller/Knowledge/Releases/Run.tsx`: названия доп. полей вместо id — только если SP-E4-01X уже передаёт их из ReleasesController; иначе оставить и записать в отчёт.
- Scope сужен до этих файлов; Engine/Dialogs/Customers/Settings закрыты и освобождены для других карточек.


## Остаток 08.11.2026 (дефекты SP-E9-01G, проверено по коду main)
- D-3.3: задачи `unknown_question` показывают «Без описания» — `text()` в `resources/js/pages/Seller/Customers/Tasks.tsx` и в `Show.tsx` читает только `payload.text`; читать `payload.text || payload.question`.
- D-3.2: axe `definition-list` на 1440/1024/390 — в `Show.tsx` внутри `<dl><div>` лежит `<p>` (история заполнения); внутри группы оставить только `dt`/`dd`, пояснение вынести в `dd`.
- D-5.1: axe `scrollable-region-focusable` на 1024/390 — у контейнеров `overflow-x-auto` в `resources/js/pages/Seller/Settings/Policy/Fields.tsx` нет фокуса: `tabIndex={0}`, `role="region"`, `aria-label`.
- Проверка: Playwright `tests/Browser/E6-10E/` с axe по трём экранам на 1440/1024/390. Остальные пункты карточки закрыты, scope сужен до этих файлов.



## Остаток 08.11.2026 (аудит экранов, планировщик)
- D-5.1 (макет `mockups D-5.1`, fields-v2): в таблице «Поля сценария» колонка «Допустимые значения» — чипы реальных значений поля (для списка), а не текст «Список текстовых значений»; если значений нет — «—».
- D-5.1: колонка «Обязательно для стадии» — подпись реальной стадии из опубликованных настроек (`required_stage`), проверить на данных, где стадия задана; «—» только при отсутствии.
- D-5.1: серая ручка перетаскивания слева у строки (только визуально, v2, `aria-hidden`), бейдж «v2» у заголовка и плашка «Макет v2: редактирование станет доступно после доработки» по макету — без включения редактирования.
- Проверка: Feature-тест значений/стадии в пропсах (права, изоляция бренда), Playwright `tests/Browser/E6-10E/` с axe на 1440/1024/390. Scope сужен до этих файлов.

Scope: `resources/js/pages/Seller/Settings/Policy/Fields.tsx`, `app/Seller/Settings/Policy/PolicySettingsController.php`, `tests/Feature/Seller/Settings/Policy/ClientFieldsE610Test.php`, `tests/Browser/E6-10E/`, `docs/results/SP-E6-10E.md`.
