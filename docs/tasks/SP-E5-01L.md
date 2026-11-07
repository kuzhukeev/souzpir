# SP-E5-01L · Доставка ответов в Telegram

**Зачем:** завершить соответствующий сценарий и экран по результату SP-E5-01H.
**Часть чего:** остаток SP-E5-01H, docs/results/SP-E5-01H.md.
**Зависимости:** SP-E5-01H.

## Приёмка
- Подключить доставку ответов кабинета к TelegramSender за выключенным флагом, разрешены необходимые изменения общего ManagerMessageTransport и binding; проверить права и изоляцию брендов на изолированной PostgreSQL, использовать fake HTTP без живых отправок.
- Сохранить существующее поведение, проверить права, ошибки и изоляцию брендов; использовать fake runtime.

Scope: `app/Seller/Channels/`, `app/Seller/Conversations/`, `tests/Feature/Seller/Channels/`, `tests/Browser/E5-01L/`, `docs/results/SP-E5-01L.md`.
