# SP-E6-03AA

Этап после слияния SP-E6-10E (карточка клиента доступна в scope).

- Карточка клиента (D-3.2): блок «Согласие на рассылку» — статус (получено/отозвано/не зафиксировано), счётчик событий отписки, форма менеджера с обязательным основанием и кнопками «Подтвердить согласие»/«Отозвать согласие». Новый маршрут `POST /seller/customers/{contact}/consent`, запись через существующий `FollowupConsent` (источник `manager`), права `seller.dialogues.operate`.
- Telegram-бот: блокировка/выход клиента из чата (`my_chat_member`, статус `kicked`/`left`) фиксируется как событие отписки (источник `dialog`) идемпотентно по `update_id`; `setWebhook` подписывается на `allowed_updates: [message, my_chat_member]`. Живые вызовы Telegram не включались, только fake/Http::fake в тестах.
- Отписка по-прежнему учитывается по всем диалогам контакта бренда (проверено: `FollowupSendTest::bulk_delivery_respects_schedule_and_revocation`, `opt_out_applies_to_all_conversations_of_the_contact`), новые события (из карточки и из Telegram) используют тот же механизм и так же блокируют отправку.
- Проверено: `php artisan test tests/Feature/Seller/Customers/CustomerConsentE603AATest.php tests/Feature/Seller/Channels/Telegram/TelegramUnsubscribeE603AATest.php tests/Feature/Seller/Followups/FollowupConsentE603AATest.php` — 6 тестов, 77 проверок; полный прогон `tests/Feature/Seller/Customers/ tests/Feature/Seller/Channels/Telegram/ tests/Feature/Seller/Followups/` — 51 тест, 984 проверки, всё зелено (регрессий нет). Playwright `tests/Browser/E6-03AA/` и `tests/Browser/E6-03K/` — 3 теста. Pint, ESLint, `tsc --noEmit`, `generate.php --check` (после регенерации `routes.d.ts`) прошли.
- Скриншоты: `.local/screenshots/SP-E6-03AA/customer-card.png` (светлая тема, 1440), `.local/screenshots/SP-E6-03AA/followups.png` (из прежней сдачи).
- Сверено с макетом карточки клиента (мокапы D-3.2) и журналом `docs/sources/02b_mockup_journal.md`.

## Этап 19:20 (последний пункт)
- Импорт CRM: в маппинге CSV (`/seller/customer-io`) новое поле «Согласие на рассылку»; значение «да/yes/1/true» в строке создаёт контакту пустой `conversation` и событие `granted` с `source=import` через `FollowupConsent`. Проверено: `ImportExportTest::test_import_with_consent_column_records_granted_event_with_import_source`.
- Виджет: после merge main обнаружился готовый `WidgetInboundRuntime`→`InboundRecorder::ingest`, который уже вызывает `FollowupConsent::inbound` для любого канала. Новый тест подтверждает: явное «да» посетителя после вопроса про рассылку в диалоге фиксируется как `source=dialog`, а согласие на передачу данных через форму `consent_url`/`/widget/contact` таким событием не считается. Проверено: `WidgetConsentE603AATest`.
- Прогон: `php artisan test tests/Feature/Seller/Customers tests/Feature/Seller/Followups tests/Feature/Seller/Channels/Telegram tests/Feature/Seller/Channels/Widget` — 64 теста, 1266 проверок, всё зелено; Pint — passed. После этого остатка по карточке нет.

## Техдолг
- Нет новых рисков.

## Принято по умолчанию
- Форма согласия на карточке клиента записывает событие против последнего диалога контакта (как и у менеджера на экране дожимов); агрегация по контакту уже учитывает это во всех его диалогах бренда.
- Событие Telegram-отписки формулируется как «клиент заблокировал бота или покинул чат» без различения kicked/left в тексте основания.
