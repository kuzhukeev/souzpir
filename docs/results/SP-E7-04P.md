# SP-E7-04P · Аналитика: первый контакт

## Сделано
- `conversations.contact_received_at` (миграция `database/migrations/E7-04P/`): nullable timestamp, пишется один раз в `FirstTurnTextFinalizer`/`SellerFollowup/FollowupFinalizer` при первом email/мессенджере в слотах.
- `AnalyticsController`: среднее время до контакта по диалогам периода (минуты, округление до 0.1), `null` без данных; в Inertia (`analytics.avgMinutesToContact`) и CSV.
- Фронтенд (D-8.2, вкладка «Диалоги», макет `mockups 145`/D-8.2): реальный текст метрики в `Details.tsx` (это и есть вкладка «Диалоги» с мокапа — рендерится через `<Details activeTab={tab} />` в `Index.tsx`) теперь показывает «Среднее время до контакта: N мин M сек.» вместо прежней заглушки «нет данных — момент не сохраняется»; `null` → «нет данных».

## Исправление 07.11.2026 21:40
- Предыдущие сдачи добавляли отдельную KPI-карточку в `Index.tsx` под вкладкой «Воронка» — это не та вкладка, что на мокапе D-8.2 (там активна «Диалоги», содержимое которой рендерит `Details.tsx`, не входивший в scope). Карточку в `Index.tsx` убрал, значение перенёс в правильное место — абзац «Аналитика диалогов» на вкладке «Диалоги» в `Details.tsx` (файл того же экрана, свободен от чужих задач — проверено по `tasks.local`). `avgMinutesToContact` оставлен в типе `Analytics` (`Index.tsx`), т.к. передаётся в `Details` через общий `data={a}`.

## Проверено
- Расчёт/права/бренды/CSV: `tests/Feature/Seller/Analytics/ContactTimeE704PTest.php`.
- Экран (вкладка «Диалоги», значение и формат как в макете): `tests/Browser/E7-04P/analytics.spec.ts` (своя Vite-фикстура и сервер на свободном порту), скриншот `.local/screenshots/SP-E7-04P/dialogs.png` сверен с `mockups D-8.2`.
- `check artisan test tests/Feature/Seller/Analytics/` — 22/22, 577 assertions.
- `check frontend` — lint 0 ошибок (5 предсуществующих warning), tsc ok, vitest 249/249, build ok.
- `HOME=/root check lint` (composer validate --strict, pint) — ok.

## Остаток
- Остальные пункты D-8.2 (длина диалога, доля завершённых ботом, тепловая карта) не трогались — сделаны ранее, поведение не менялось.

## Техдолг
- Событие `contact_received_at` пишется только в защищённых финализаторах (первый ход, follow-up API); иные гипотетические пути заполнения слотов этот момент не затронут — риск редкий.

## Принято по умолчанию
- Показатель — среднее в минутах по диалогам периода (не медиана), без разбивки по каналам/менеджерам.
