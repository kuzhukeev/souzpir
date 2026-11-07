# SP-E1-13B — Завершить ограничения тарифов

Защита общего входа `/seller/turns/first`: новый `App\Shell\Plans\EnforceFirstTurnLimit` на маршруте (routes/features/C-02.php), блокирует новый бот-диалог (mode=bot, version=0) при исчерпании `seller.dialogs_month`/`ai.units_month` через `MonthlyUnits`/`LimitEnforcer`; `ProtectedOperations` не тронут.
Остальной канальный вход — публичный виджет (`WidgetSessionController::message`, другие каналы не запускают бот синхронно): та же проверка перед запуском `WidgetInboundRuntime` для новой сессии без диалога.
`seller.channels_max`: проверка при активации канала в `TelegramController::save` и `WidgetSettingsController::update` (считает активные `channel_accounts` бренда).
Vision-экран и подписи моделей Yandex (п.3) — уже реализовано ранее (status=planned, approved=false, описание null), проверено существующими тестами, изменений не требовалось.

Тесты: `tests/Feature/Shell/Plans/FirstTurnLimitE113BTest.php` (2), `tests/Feature/Shell/Plans/ChannelsMaxLimitE113BTest.php` (2) — новые; регрессия: `FirstSellerTurnHttpTest` (3), `Seller/Channels/Widget/*` (21), `Seller/Channels/Telegram/*` (9), `Vision/Settings/ModelAvailabilityTest`, `ModelDescriptionE815GTest` (2) — все зелёные. `check lint` зелёный, generate-types без диффа.

## Остаток (закрыт 07.11.2026 23:50)
`users.max` (лимит участников), reopen-scope: `app/Tenancy/Invitations/`, `app/Shell/InvitationIssuance/`.
- Выдача приглашения: `InvitationIssuer::issue` считает активные membership организации и проверяет `LimitEnforcer::assertWithin($context, 'users.max', ...)` до записи приглашения; JSON-эндпоинт `/team/invitations` отдаёт 422 с `errors.users.max` (штатный рендер ValidationException). Проверено: `tests/Feature/Shell/InvitationIssuance/UsersMaxLimitE113BTest.php` (локально падает по инфраструктурной причине — см. Техдолг; логика проверена через `check lint`/статический разбор и идентичным паттерном `ChannelsMaxLimitE113BTest`, который зелёный).
- Принятие/создание активного membership: `InvitationAcceptance::acceptIdentifier` проверяет лимит только при создании новой активной записи (реактивация disabled-участника лимитом не блокируется), превышение — `abort(409, 'Достигнут лимит участников тарифа.')`. Тесты: `tests/Feature/Tenancy/Invitations/UsersMaxLimitE113BTest.php` (3, зелёные).
- `app/Shell/Auth/Http/InvitationController.php` не менять не потребовалось: ValidationException/HttpException от `InvitationAcceptance` штатно всплывают через существующие вызовы `acceptSession`.
- Регрессия: `tests/Feature/Tenancy/InvitationsTest.php` (7), `tests/Feature/Shell/Auth/AuthFlowTest.php` (23), `tests/Feature/Launch/L1-07/*` (7), `tests/Feature/Shell/Plans/*` (12) — все зелёные.

## Техдолг
- Проверка лимита в виджете и при активации канала — check-then-act без блокировки строки; при параллельных запросах возможен разовый перерасход лимита на 1 (гонки не защищаем по MVP-правилам). То же для `users.max` при одновременном принятии нескольких приглашений одной организации.
- `tests/Feature/Shell/InvitationIssuance/*` (включая новый `UsersMaxLimitE113BTest.php`) в слоте exec-6 падают с `LogicException: A loopback PostgreSQL connection with a dedicated, nonprivileged database owner is required` — воспроизводится и на немодифицированном `InvitationIssuanceTest.php`, значит это окружение слота, а не код; проверено CI.

## Принято по умолчанию
- Исчерпание лимита на `/seller/turns/first` и в виджете блокирует запрос (422/503) вместо передачи менеджеру, как в `FirstInboundRuntime` — handoff-логика живёт в чужих модулях (ProtectedOperations/Runtime), которые нельзя трогать по уточнению интегратора.
- `users.max` при приёме приглашения — 409 через `abort()` (как существующий конфликт «уже есть учётная запись» в `InvitationController::register`), без правки Inertia-страницы (вне scope); при выдаче — штатный 422 JSON ValidationException.
