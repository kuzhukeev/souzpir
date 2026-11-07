

## Остаток 07.11.2026 (после возврата в очередь)
События согласия dialog/manager на экране дожимов, D-2.5, миграция E6-03Z и browser-тест уже в main. Остались конкретные пути:
- Карточка клиента D-3.2: отметка согласия на рассылку и его снятие менеджером с обязательным комментарием (источник `manager`), счётчик событий отписки; после снятия отправка запрещена.
- Блокировка бота/адреса каналом Telegram как событие отписки: обрабатывать `my_chat_member` (kicked/left) в webhook, подписывать webhook на `allowed_updates` с `my_chat_member`; fake-транспорт, без живых вызовов.
- Согласие из импорта CRM (`import`) и из переписки виджета (`dialog`) выполнять в этой же карточке после done SP-E1-10K и SP-E5-02C; до этого не трогать `app/Seller/Channels/Crm/` и виджет.

Дополнительный scope: `app/Seller/Customers/`, `resources/js/pages/Seller/Customers/`, `tests/Feature/Seller/Customers/`, `app/Seller/Channels/Telegram/TelegramWebhookController.php`, `app/Seller/Channels/Telegram/HttpTelegramTransport.php`, `tests/Feature/Seller/Channels/Telegram/` — брать после done SP-E6-10E.
