# SP-E7-04M · Источники обращений и переходы

**Зачем:** завершить экран по принятому результату.
**Часть чего:** остаток SP-E7-04F, docs/results/SP-E7-04F.md.
**Зависимости:** SP-E7-04F.

## Приёмка
- Записать переходы стадий во всех защищённых путях и действиях менеджера; сохранять источники, UTM и адреса страниц при приёме обращений и показывать в D-8.4, сверив макеты.
- Проверить права, изоляцию брендов, ошибки и состояния; незавершённое указать в «Остаток». Без живых и платных вызовов.

Scope: `app/Seller/Analytics/`, `app/Seller/Runtime/`, `app/Seller/Channels/`, `app/ProtectedOperations/`, `resources/js/pages/Seller/Analytics/`, `tests/Feature/Seller/Analytics/`, `tests/Browser/E7-04M/`, `docs/results/SP-E7-04M.md`.

## Решение владельца 06.11.2026 — общие AI-стадии

Решение владельца 06.11: разрешены только совместимые добавления общих AI-стадий без рефакторинга и без изменения существующего поведения и записанного replay; проверить права, изоляцию брендов и повторы. Только fake AI; живые и платные вызовы выключены. Общий ресурс shared-ai-stages занимает одна карточка до слияния. Прежние ограничения «до решения владельца» считать снятыми в этих границах.

## Уточнение scope 07.11.2026 20:10
Ресурс shared-ai-stages не нужен (AI-стадии не меняются). Scope сужен до конкретных файлов: `app/Seller/Analytics/`, `resources/js/pages/Seller/Analytics/`, `tests/Feature/Seller/Analytics/`, `tests/Browser/E7-04M/`, `docs/results/SP-E7-04M.md`; запись переходов стадий — `app/Seller/Runtime/FirstInboundRuntime.php`, `TokenInboundRuntime.php`, `ApiOpeningRuntime.php`, `ApiFollowupRuntime.php`, `WidgetInboundRuntime.php`, `app/ProtectedOperations/FirstTurnTextFinalizer.php`, `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php`, `app/Seller/Dialogs/DialogsController.php` (действия менеджера); источники/UTM/адреса страниц при приёме — `app/Seller/Channels/Api/LeadIngressController.php`, `ApiSourceReceipt.php`, `app/Seller/Channels/Widget/WidgetSessionController.php`, `PublicWidgetController.php`; при необходимости колонок — `database/migrations/E7-04M/`. `app/Seller/Runtime/StageTransitions.php` принадлежит SP-E6-07D — использовать как есть, правки в него — пункт «Нужно от интегратора».


## Уточнение scope 08.11.2026 00:30 (планировщик)
- Остаток после слияния c1f406ac: D-8.4 — `resources/widget/widget.js` передаёт `page` (location.pathname) и `utm` (utm_* из location.search) в `/widget/session`, `WidgetSessionController::start()` сохраняет их в `widget_sessions.page/utm`; D-8.1 — источник API-обращений (`LeadIngressController`, `ApiSourceReceipt`, `ApiOpeningRuntime`).
- Финализаторы, `FirstInboundRuntime`, `TokenInboundRuntime`, `ApiFollowupRuntime`, `WidgetInboundRuntime`, `PublicWidgetController`, `DialogsController` из scope убраны: переходы стадий уже влиты, файлы освобождены для SP-E4-04F. `widget.js` занят SP-E5-02D — брать после его слияния.
