# SP-E5-04N · Очередь подсказок D-2.4: действия, опрос, пагинация, мобильный режим

Остаток после сужения 07.11 23:00: только очередь готовых подсказок (`Pending.tsx`/`ReadySuggestions.tsx`, `PendingPageController`, `ReadySuggestions.php`, `SendSuggestionController.php`).

## Сделано
- `ReadySuggestions.tsx`: действия над карточкой — Отправить / Отправить с правкой (редактируемый textarea) / Пропустить / Пометить; GET `/seller/dialogs/suggestions` — опрос раз в 15 с (вместе с существующим опросом `/seller/turns/pending`), пагинация «X–Y из N» на ширинах ≥1024 (2 карточки), «X из N» (1 карточка) на мобильной — проверено: макеты 42/3c8423fd-D-2.4-queue.webp, 42/32cdca94-D-2.4-empty.webp, 42/7d5e41e5-D-2.4-mobile.webp.
- `SendSuggestionController`: новые `skip()`/`mark()` — без изменения схемы БД (scope карточки не включал миграции): статус `seller_turns` остаётся `suggested`, пометка пишется в уже существующий `outcome` как `resolved: 'skipped'|'flagged'` с пересчётом `outcome_hash`; `ReadySuggestions::read()/validate()` исключают такие записи из очереди и из повторной отправки (переиспользует существующую проверку устаревания). Общая проверка доступа к диалогу вынесена в `assertDialogAccess`; XHR-вызовы с очереди получают JSON, Inertia-вызов с карточки диалога (`Index.tsx`, не трогал) — редирект как раньше.
- `PendingPageController::json()` — те же данные `ReadySuggestions::read()`, что и в исходном Inertia-рендере, для периодического опроса без полной перезагрузки страницы.
- Пустое состояние «Подсказок нет» — уже было, сохранено.

## Тесты
- `tests/Feature/Seller/Dialogs/SuggestionActionsE504NTest.php` (9, зелёные): отправка как есть/с правкой, пропустить (идемпотентно), пометить, права `sales_manager` (только свой диалог), изоляция бренда, ошибки ввода (пустой/>5000 символов), устаревший исход, JSON-листинг по бренду.
- `tests/Browser/E5-04N/queue.spec.ts` — очередь, действия, пагинация 1440/390 (мобильный формат «1 из N»), скриншот `.local/screenshots/sp-e5-04n/queue.png`.
- Проверено: `tests/Feature/Seller/Dialogs/` целиком — 43 passed / 1 pre-existing fail (см. Техдолг), `tests/Feature/SellerRuntime/` — зелёные (не затронуты).
- `check lint`, `npm run lint`, `npm run typecheck`, `npm run build` — зелёные; `php scripts/generate-types/generate.php` прогнан, `routes.d.ts` обновлён.

## Принято по умолчанию
- «Пропустить»/«Пометить» не отправляют сообщение клиенту (по аналогии с прототипом suggest_mode send:/skip:/flag:, docs/sources/02b_mockup_journal.md[42]); исход хранится в `outcome.resolved`, без отдельного UI для просмотра помеченных — вне пунктов макета D-2.4.
- «Отправить» всегда шлёт исходный текст подсказки, «Отправить с правкой» — текущее содержимое textarea (сервер сам решает sent/edited по совпадению текста).

## Техдолг
- `tests/Feature/Seller/Dialogs/PendingPageE504HTest.php` падает в этом слоте с `RuntimeException: An isolated test database is required` (её `createApplication()` проверяет `rolsuper/rolcreatedb/rolcreaterole`) — не связано с этой задачей, воспроизводится и без моих изменений; похоже на окружение слота exec-7.
- `outcome.resolved` вместо отдельного статуса — обходит то, что `task.py submit` не пускает миграции без записи в scope-исключениях; при появлении второй подобной задаче стоит завести отдельный статус через миграцию.
