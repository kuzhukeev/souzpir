# SP-E6-08G — Каталог: цены и fake-проверка

- D-10.1: подключил `catalog_prices_enabled`/`catalog_discounts_enabled` к движку. `check_stock` отдаёт `price`/`wholesale_price` позиции только когда соответствующий флаг бренда включён (по умолчанию — как раньше, без цен). Guard (`DeterministicGuard`) блокирует в ответе любую цену в рублях, которая не совпадает с ценой товара, реально найденного через `check_stock` в этом ходе — числовые факты ограничены товарами, подтверждёнными в разговоре, а не всем каталогом. Пробрасывается из `FirstTurnDeterministicGuard` (протокол защищённого первого хода).
- D-10.1 fake-отправка прайса: уже реализована ранее (SP-E5-07, `ProtectedPriceSender`) — отправляет только когда `mail.default`/driver фейковые; при живых настройках пишет задачу менеджеру `price_send_failed`. В этой задаче не менялась, проверено существующим `CatalogToolsTest::test_price_requires_opt_in_and_file_and_never_sends_live` (вне scope-папки, не перезапускал).
- D-10.4: `check_stock` уже защищён по бренду/организации (`ToolRunner::catalog`, тест на cross-brand), ошибки чтения каталога не ломают экран (`itemsError`). Передача «нет в наличии» менеджеру идёт через существующий путь `unknown_fact` → `log_unknown_question` (готовая задача менеджеру + обещание клиенту) — отдельно не перепроверял сквозь `FirstTurnCoordinator`, см. Остаток.
- SP-E9-01F: таблица товаров на 390 обёрнута в `role="region" tabIndex=0` — axe `scrollable-region-focusable` больше не находит нарушений (перепрогнал `e901f-catalog.spec.ts`, 0 violations на 1440/1024/390). Пример ответа на `/seller/integrations/stock` теперь показывает «осталось мало (N). Уточните наличие у менеджера» при остатке ≤ порога.
- Макеты сверял: `150/3dcae91c-D-10.1-settings`, `152/381527a2-D-10.4-stock`.

## Тесты
- `tests/Unit/Seller/Engine/DeterministicGuardTest.php` — 3 новых кейса (цена не из check_stock блокируется, совпавшая цена проходит, выключенная настройка не влияет).
- `tests/Feature/Seller/Catalog/CatalogToolPricesE608GTest.php` — price/wholesale_price скрыты по умолчанию и видны только при включённых флагах бренда.
- `tests/Browser/E6-08G/catalog-prices.spec.ts` — клавиатурный фокус на таблице (390px), фраза «мало» в примере ответа; скриншот `.local/runtime/e6-08g-stock.png`.
- `check artisan test tests/Feature/Seller/Catalog/ tests/Unit/Seller/Engine/` — 136 passed. `check frontend` и `check lint` — зелёные.

## Остаток
- D-10.4: не перепроверял весь защищённый путь `check_stock` через `FirstTurnCoordinator`/`FirstTurnTextFinalizer` end-to-end (тесты на `DatabaseMigrations`, например `FirstTurnStateTest.php`, в слоте exec-2 падают на «An isolated test database is required» из-за `rolcreatedb=true` у роли слота — не связано с этой задачей). Логика тулов проверена на уровне `ToolRunner`.
- D-10.1: путь check_stock→Guard подключён только для первого хода; в follow-up (`SellerFollowup/*`) `ctx` с ценами не передаётся — там поведение как раньше (цены не называются).

## Техдолг
- Проверить `FirstTurnStateTest`-подобные тесты в exec-слоте с ролью без CREATEDB либо поправить сам guard — иначе эти тесты нельзя прогнать локально ни в одной задаче, трогающей защищённый первый ход.

## Принято по умолчанию
- «Скидки» трактовал как `wholesale_price` (опт) — в макете отдельного поля для скидок нет, а toggle «скидки бренда» в коде уже существовал без применения.
