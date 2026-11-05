# SP-E5-07D · Почта: защищённая отправка прайса

**Зачем:** закрыть остаток по решению владельца 05.11.2026.
**Часть чего:** SP-E5-07B, docs/results/SP-E5-07B.md.
**Зависимости:** SP-E5-07B.

## Приёмка
- Подключить защищённый send_price к PriceMailSender, создать задачу менеджеру при отказе, обеспечить переход из общей навигации и проверить сквозной сценарий на fake-транспорте. Не использовать живую почту. Общие AI-стадии не изменять до решения владельца.
- Проверить основной сценарий, права и изоляцию брендов, ошибки и состояния; остаток зафиксировать в отчёте.

Scope: `app/Seller/Channels/Email/`, `app/Seller/Engine/`, `app/ProtectedOperations/`, `resources/js/pages/Seller/Channels/Email/`, `tests/Feature/Seller/Channels/Email/`, `tests/Feature/ProtectedOperations/`, `tests/Browser/E5-07D/`, `docs/results/SP-E5-07D.md`.
