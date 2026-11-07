# SP-E1-13B — Завершить ограничения тарифов

Защита общего входа `/seller/turns/first`: новый `App\Shell\Plans\EnforceFirstTurnLimit` на маршруте (routes/features/C-02.php), блокирует новый бот-диалог (mode=bot, version=0) при исчерпании `seller.dialogs_month`/`ai.units_month` через `MonthlyUnits`/`LimitEnforcer`; `ProtectedOperations` не тронут.
Остальной канальный вход — публичный виджет (`WidgetSessionController::message`, другие каналы не запускают бот синхронно): та же проверка перед запуском `WidgetInboundRuntime` для новой сессии без диалога.
`seller.channels_max`: проверка при активации канала в `TelegramController::save` и `WidgetSettingsController::update` (считает активные `channel_accounts` бренда).
Vision-экран и подписи моделей Yandex (п.3) — уже реализовано ранее (status=planned, approved=false, описание null), проверено существующими тестами, изменений не требовалось.

Тесты: `tests/Feature/Shell/Plans/FirstTurnLimitE113BTest.php` (2), `tests/Feature/Shell/Plans/ChannelsMaxLimitE113BTest.php` (2) — новые; регрессия: `FirstSellerTurnHttpTest` (3), `Seller/Channels/Widget/*` (21), `Seller/Channels/Telegram/*` (9), `Vision/Settings/ModelAvailabilityTest`, `ModelDescriptionE815GTest` (2) — все зелёные. `check lint` зелёный, generate-types без диффа.

## Остаток
- `users.max` (лимит участников): точка создания активного membership — `app/Tenancy/Invitations/InvitationAcceptance.php`, выдача приглашения — `app/Shell/InvitationIssuance/InvitationIssuer.php`, принятие — `app/Shell/Auth/Http/InvitationController.php`. Все вне scope карточки (app/Shell/Team, app/Shell/Auth, app/Tenancy).

## Техдолг
- Проверка лимита в виджете и при активации канала — check-then-act без блокировки строки; при параллельных запросах возможен разовый перерасход лимита на 1 (гонки не защищаем по MVP-правилам).

## Принято по умолчанию
- Исчерпание лимита на `/seller/turns/first` и в виджете блокирует запрос (422/503) вместо передачи менеджеру, как в `FirstInboundRuntime` — handoff-логика живёт в чужих модулях (ProtectedOperations/Runtime), которые нельзя трогать по уточнению интегратора.

## Нужно от интегратора
- Отдельная карточка на `users.max` с scope `app/Tenancy/Invitations/`, `app/Shell/InvitationIssuance/`, `app/Shell/Auth/Http/InvitationController.php`.
