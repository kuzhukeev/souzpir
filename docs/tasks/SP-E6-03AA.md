

## Остаток 07.11.2026 (после возврата в очередь)
События согласия dialog/manager на экране дожимов, D-2.5, миграция E6-03Z и browser-тест уже в main. Остались конкретные пути:
- Карточка клиента D-3.2: отметка согласия на рассылку и его снятие менеджером с обязательным комментарием (источник `manager`), счётчик событий отписки; после снятия отправка запрещена.
- Блокировка бота/адреса каналом Telegram как событие отписки: обрабатывать `my_chat_member` (kicked/left) в webhook, подписывать webhook на `allowed_updates` с `my_chat_member`; fake-транспорт, без живых вызовов.
- Согласие из импорта CRM (`import`) и из переписки виджета (`dialog`) выполнять в этой же карточке после done SP-E1-10K и SP-E5-02C; до этого не трогать `app/Seller/Channels/Crm/` и виджет.

Дополнительный scope: `app/Seller/Customers/`, `resources/js/pages/Seller/Customers/`, `tests/Feature/Seller/Customers/`, `app/Seller/Channels/Telegram/TelegramWebhookController.php`, `app/Seller/Channels/Telegram/HttpTelegramTransport.php`, `tests/Feature/Seller/Channels/Telegram/`, `app/Seller/Channels/Widget/`, `tests/Feature/Seller/Channels/Widget/`, `tests/Browser/E6-10E/`, `tests/Browser/E5-05D/`, `tests/Browser/E5-05F/`.


## Этап 07.11.2026 18:30
- SP-E6-10E влита в main: выполнять сейчас пункты «Карточка клиента D-3.2» (согласие/снятие менеджером с комментарием, счётчик отписок, запрет отправки) и «Блокировка Telegram как отписка» (my_chat_member, fake-транспорт). Пункт «согласие из импорта CRM и виджета» остаётся остатком этой же карточки до done SP-E1-10K и SP-E5-02C — `app/Seller/Channels/Crm/` и виджет не трогать.

## Этап 07.11.2026 19:20
- SP-E5-02C/F в main; импорт CRM живёт в `app/Seller/Customers/ImportExport/` (уже в scope), поэтому ждать SP-E1-10K (на доработке, её файлы `app/Shell/`, `app/Seller/Channels/Crm/` не трогать) не нужно. Выполнить последний пункт: согласие на рассылку из импорта CRM (источник `import`, явно обозначенное поле согласия в `app/Seller/Customers/ImportExport/`, тест в `tests/Feature/Seller/Customers/`) и подтвердить тестом, что явное «да» посетителя виджета через `WidgetInboundRuntime` → `InboundRecorder` записывается как событие `dialog` (тест в `tests/Feature/Seller/Channels/Widget/`); согласие на передачу данных в виджете (`consent_url`) согласием на рассылку не считать. `app/Seller/Channels/Crm/` (исходящие вебхуки) не нужен. После этого остатка по карточке нет.

## Этап 07.11.2026 20:00 (красные browser-тесты карточки клиента)
- В полном browser-прогоне на main падают `tests/Browser/E6-10E/customer.spec.ts` (нет «Марка и модель» на карточке клиента), `tests/Browser/E5-05D/customers.spec.ts` (нет кнопки «Скопировать Телефон»), `tests/Browser/E5-05F/customers.spec.ts` (нет «Фейерверк · Тверь» в списке). Починить в этой карточке: проверить, что изменения карточки клиента (согласие/отписка) не сломали вывод доп. полей, телефона и названия компании; тесты не ослаблять.
