# SP-E7-04P · Аналитика: первый контакт

**Зачем:** завершение экранов по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E7-04O, docs/results/SP-E7-04O.md.
**Зависимости:** SP-E7-04O.

## Приёмка
- D-8.2: событие первого получения контакта в защищённом финализаторе, расчёт времени; для старых диалогов показывать «Нет данных», проверить права и бренды.
- Проверить основной сценарий, права, изоляцию брендов, ошибки и макет; живые внешние вызовы отключены. Остаток оформить по AGENTS.md.

Scope: `app/ProtectedOperations/FirstTurnTextFinalizer.php`, `app/ProtectedOperations/SellerFollowup/FollowupFinalizer.php`, `app/Seller/Analytics/`, `database/migrations/E7-04P/`, `tests/Feature/Seller/Analytics/`, `tests/Browser/E7-04P/`, `docs/results/SP-E7-04P.md`.


## Уточнение scope 07.11.2026 18:30
- Из `app/ProtectedOperations/` в scope только `FirstTurnTextFinalizer.php` и `SellerFollowup/FollowupFinalizer.php`; тесты писать в `tests/Feature/Seller/Analytics/`. Пока SP-E5-02C держит `SellerFollowup/`, карточка недоступна.

## Остаток 07.11.2026 20:20 (после слияния backend)
- Backend влит: `conversations.contact_received_at`, запись в финализаторах, `analytics.avgMinutesToContact` в Inertia и CSV. Осталось: KPI-карточка «Среднее время до контакта» на `/seller/analytics` рядом с «Диалоги по дням» по макету 145/D-8.2 (`mockups D-8.2`): значение в минутах, `null` → «Нет данных»; состояния loading/empty, три темы, ширины 1440/1024/390. Browser-тест в `tests/Browser/E7-04P/` или проверка Inertia-пропса в `tests/Feature/Seller/Analytics/`.
- Scope дополнен: `resources/js/pages/Seller/Analytics/Index.tsx`.

## Остаток 07.11.2026 20:20 (после слияния backend)
- Backend влит: `conversations.contact_received_at`, запись в финализаторах, `analytics.avgMinutesToContact` в Inertia и CSV. Осталось: KPI-карточка «Среднее время до контакта» на `/seller/analytics` рядом с «Диалоги по дням» по макету 145/D-8.2 (`mockups D-8.2`): значение в минутах, `null` → «Нет данных»; состояния loading/empty, три темы, ширины 1440/1024/390. Browser-тест в `tests/Browser/E7-04P/` или проверка Inertia-пропса в `tests/Feature/Seller/Analytics/`.
- Scope дополнен: `resources/js/pages/Seller/Analytics/Index.tsx`.
