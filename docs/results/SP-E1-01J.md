# SP-E1-01J — поле кода только при заданном коде

- S-0.2: `RegisterController::create()` передаёт `needsCode` (булево, без хэша/значения кода); `Register.tsx` показывает поле «Код создания кабинета» и подсказку только при `needsCode`. Проверено: макет `[2]` журнала (поле отсутствует, когда код не настроен) — `completed/2/f00690a4-S-0.2-register-eyon.webp`, скриншот `.local/screenshots/SP-E1-01J/register-no-code.png`.
- `store()`: при пустом/невалидном `bootstrap_token_hash` код не требуется в валидации и не проверяется (значение из запроса просто не читается); при заданном хэше — прежняя проверка `hash_equals(sha256)`, длина 32–256, общая русская ошибка без деталей. Проверено: `RegisterBootstrapE101JTest`.
- Правило одного владельца не менялось (транзакция + `lockForUpdate` в `RegisterController::store`); тест `test_second_registration_without_code_is_rejected_once_organization_has_an_owner` — вторая регистрация без кода после первой отклоняется ошибкой `bootstrap_code`, как и в режиме с кодом.
- Тесты: `RegisterBootstrapE101JTest` — 5 тестов, 40 проверок (props, без кода/с кодом, один владелец); `AuthFlowTest` (25) и `VerificationIntegrationTest` (5) — регрессия зелёная. Playwright `tests/Browser/E1-01J/register-no-code.spec.ts` — 3 сценария (1440/390 без поля, с кодом поле видно); `tests/Browser/E1-01I/auth.spec.ts` — 6 сценариев зелёные (фикстура дополнена `needsCode`, по умолчанию `true` — старые тесты с кодом не изменены). `composer lint`, `npm run lint/typecheck/test/build` — зелёные.
- Запись в `docs/decisions-pending.md` про код на проде при развёртывании уже внесена ранее (строка 08.11 «владелец через Claude»), отдельно не дублировал.

## Техдолг
- Нет.

## Принято по умолчанию
- Фикстура `tests/Browser/E1-01I/fixture.tsx` дополнена пропом `needsCode` (по умолчанию `true` через `?needsCode=0|1`), чтобы существующий сценарий с полем кода не сломался после правки `Register.tsx`.

## Нужно от интегратора
- Нет.
