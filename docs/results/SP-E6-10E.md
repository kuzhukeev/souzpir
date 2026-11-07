# SP-E6-10E — доп. поля клиента в движке, диалогах и карточке клиента

- Extractor собирает опубликованные дополнительные поля (text/number/select → enum); контактные слоты (имя, почта, мессенджер, следующий контакт) собираются для любого бренда, остальные оптовые слоты (город, цель, опыт и т.д.) — только для подтверждённого прототип-бренда «Союз Пир»; остальные бренды начинают с пустого набора оптовых полей.
- Policy: `nextSlot`/`computeMissing` учитывают обязательные доп. поля; `stageBySlots` для неоптовых брендов продвигается по обязательным доп. полям стадии вместо оптовых слотов — иначе бренд без оптовых полей навсегда застревал бы на стадии «Знакомство».
- Карточка диалога (DialogReader/DialogProfileController, Index.tsx) показывает и позволяет редактировать опубликованные доп. поля с их названиями; убран хардкод «16 полей клиента».
- Карточка клиента (D-3.2): доп. поля вынесены в отдельный блок «Остальные поля» с названиями из опубликованной схемы (Show.tsx, CustomersController).
- Settings/Policy/Fields: модалка «Схема полей» (D-5.1) показывает опубликованные доп. поля вместе с системными; черновик пустого списка полей для подтверждённого прототип-бренда предзаполняется оптовыми слотами как редактируемыми полями.
- Проверки: `php artisan test` — новые файлы (ClientFieldsExtractionE610ETest, PrototypeFieldsE610ETest, DialogCustomFieldsE610ETest, CustomFieldLabelsE610ETest) 8 тестов/66 проверок — все зелёные; сопутствующие существующие наборы (Engine, Dialogs, Customers, Settings/Policy) без регрессий (2 предсуществующих красных теста окружения — isolated-DB проверка, не связаны с задачей). `composer lint` (Pint) зелёный. `check frontend` — eslint (только предсуществующие warning), tsc, vitest 249/249, build — зелёные. Playwright `tests/Browser/E6-10E` (2 теста) — зелёные.
- Скриншоты: `.local/screenshots/SP-E6-10E/fields-schema.png`, `.local/screenshots/SP-E6-10E/customer-card.png` (светлая тема, 1440).
- Проверки 07.11 (после сверки с макетом): `php artisan test tests/Feature/Seller/Customers` — 18/18 зелёных (562 проверки); `tests/Feature/Seller/Engine`, `tests/Feature/Seller/Dialogs`, `tests/Feature/Seller/Settings/Policy` — без новых регрессий (падают только предсуществующие isolated-DB тесты окружения, не связанные с задачей). `tsc --noEmit`, eslint на изменённых файлах, Pint — чисто. Playwright `tests/Browser/E6-10E` — 2/2 зелёные.

## Остаток 07.11.2026 (построчная сверка D-3.1/D-3.2)
- Сверка с макетами `D-3.1-customers`, `D-3.2-customer-main/fields/dialogs/tasks/promises/notes`: порядок колонок списка клиентов (Город перед Типом) приведён к макету; вкладки «Диалоги» и «Задачи и обещания»/«Что обещали отправить» карточки клиента переведены со списков на таблицы с колонками макета (Канал/Дата/Стадия/Итог и Задача/Срок/Статус/Ответственный, Материал/Дата/Канал/Статус).
- `app/ProtectedOperations/DraftSimulation/FixtureRuntime.php` и `tests/Feature/ProtectedOperations/` по решению владельца 07.11.2026 временно заняты SP-E5-03F: `validateExtraction` всё ещё отбрасывает slot_updates вне `LeadState::SLOTS`, поэтому песочница (`/seller/knowledge/sandbox/reply`) не подтверждает заполнение доп. полей через fixture-движок — доделать в этой же карточке после освобождения файлов.
- `resources/js/pages/Seller/Knowledge/Releases/Run.tsx` отображает доп. поля по сырому id (бэкенд `app/Seller/Knowledge/Releases/ReleasesController.php` принадлежит SP-E4-01X, вне scope карточки — не передаёт имена опубликованных полей).
- Не перенесено (риск регрессии/вне бюджета задачи, записано техдолгом): список клиентов показывает один канал последнего диалога вместо нескольких иконок как на макете; блок «Данные клиента» показывает общую «Историю заполнения» одним списком вместо сворачивания «Остальные поля ниже» и отдельной ссылки «История изменений» на каждое поле; фильтр «Период» в списке клиентов — диапазон дат, а не пресет-список как в макете.

## Техдолг
- `Policy::nextSlot` для неоптовых брендов сначала перебирает сконфигурированный `slot_order` (может включать оптовые слоты, которые Extractor больше не собирает) и только потом обязательные доп. поля — несколько лишних вопросов до того, как `wasAsked()` их исчерпает; реальный фикс — настраивать `slot_order` на экране «Порядок полей» под конкретный бренд.

## Принято по умолчанию
- Подтверждённый прототип-бренд определяется персоной `company === 'Союз Пир'` (уже использовалось в Policy для похожих legacy-проверок).
- Контактные поля (name, email, messenger, next_contact_at) считаются универсальными, а не «оптовыми», и собираются для любого бренда.

## Нужно от интегратора
- Передать доп. поля в `ReleasesController::runScenarios` для `Releases/Run.tsx` (вне scope карточки; SP-E4-01X всё ещё status=queue — названия всё ещё по сырому id).
- Устаревшая ветка на origin устранена merge-коммитом `3c195095`; `git push origin codex/sp-e6-10e` прошёл (origin указывает на `2bfd9626`).

## Доработка 07.11.2026 (после done SP-E5-03F)
- `FixtureRuntime::validateExtraction` больше не отбрасывает `slot_updates` с опубликованными доп. полями (ключи вне `LeadState::SLOTS`): собирает их id из `$draft->policy()['client_fields']`, для `select` с непустыми `options` проверяет значение по enum, иначе — строка/null (как в `Extractor::schema()`); `refused_slots` тоже принимает доп. поля.
- Тест: проверено — `php artisan test tests/Feature/Seller/Engine/PublishedFieldsSandboxReplyE610ETest.php` (1/1, 7 проверок) — валидная и ошибочная `select`-доп. поле через `/seller/knowledge/sandbox/reply` (не simulation); тест красный без фикса (проверено откатом правки). Регрессии: `php artisan test tests/Feature/Seller/Engine tests/Feature/Seller/Dialogs tests/Feature/Seller/Customers tests/Feature/Seller/Settings/Policy tests/Feature/Seller/Sandbox` — 81 зелёных/2 падают (предсуществующие isolated-DB тесты окружения, не связаны с задачей). Pint — чисто.
- Run.tsx по сырому id — не изменено: SP-E4-01X ещё status=queue, бэкенд пока не передаёт имена доп. полей.

## Повторная проверка 07.11.2026 (exec-3, слот exec_3)
- Перепроверено: `php artisan test tests/Feature/Seller/Engine/PublishedFieldsSandboxReplyE610ETest.php` — 1/1 (7 проверок), зелёный на коммите `2bfd9626`.
- Регрессии в слоте exec_3: `php artisan test tests/Feature/Seller/Engine tests/Feature/Seller/Dialogs tests/Feature/Seller/Customers tests/Feature/Seller/Settings/Policy tests/Feature/Seller/Sandbox` — 106 зелёных/8 падают с «An isolated test database is required» (TenancyTestCase/IsolatedDatabaseMigrations) — окружение слота, не связано с FixtureRuntime; не трогал.
- Изменений кода не вносил, сдаю как подтверждённую «доработку» по `.local/queue.md`.

## Доработка 08.11.2026 (exec-10): D-3.3/D-3.2/D-5.1
- D-3.3: `Tasks.tsx`/`Show.tsx` читали только `payload.text` — для задач `unknown_question` (payload `{question}`) показывали «Без описания»; теперь `text() || question()`.
- D-3.2: в `Show.tsx` внутри `<dl>` группы `<p>«Источник: …»` лежал рядом с `dt`/`dd` — перенёс текст источника внутрь `dd` (axe `definition-list`).
- D-5.1: у `overflow-x-auto` контейнеров в `Fields.tsx` нет фокуса — добавил `tabIndex={0} role="region" aria-label` (axe `scrollable-region-focusable`).
- Тесты: `tests/Browser/E6-10E/{customer,fields,tasks}.spec.ts` — 6/6 зелёных, включая новые axe-проверки по трём экранам на 1440/1024/390 (0 critical/serious находок, JSON в `.local/screenshots/SP-E6-10E/*-axe.json`); новый `tasks-fixture` с задачей `unknown_question`. Регрессии: `php artisan test tests/Feature/Seller/Customers` 18/18, `tests/Feature/Seller/Settings/Policy` 36/37 (1 падает из-за отсутствия выделенной роли БД в слоте — не связано с правкой). `npx vitest run` 249/249, `tsc --noEmit`, `eslint`, `composer lint` (Pint), `npm run build` — чисто.

### Остаток
- Нет: все три пункта остатка карточки (D-3.3, D-3.2, D-5.1) закрыты по макету и проверены axe.
