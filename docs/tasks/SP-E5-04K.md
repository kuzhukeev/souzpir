# SP-E5-04K · Очередь готовых подсказок и подтверждение

**Зачем:** завершить экраны D-2.4 по решению владельца от 03.11.2026.
**Часть чего:** остаток SP-E5-04H из docs/results/SP-E5-04H.md.
**Зависимости:** SP-E5-04H, SP-E5-04J (дождаться merge).

Реализовать защищённую очередь готовых подсказок с клиентом, каналом, репликой и ответом; отправку, отправку с правкой и отклонение через защищённый контур и fake-транспорт, сохранив существующие JSON-маршруты; завершить оформление и фокус.

Сверить с docs/sources/02b_mockup_journal.md; проверить сценарии, права, изоляцию брендов и ошибки. Без живых или платных вызовов в тестах.

Scope: `app/Seller/Dialogs/`, `app/ProtectedOperations/`, `resources/js/pages/Seller/Dialogs/`, `routes/features/E5-04.php`, `tests/Feature/Seller/Dialogs/`, `tests/Feature/ProtectedOperations/`, `tests/Browser/E5-04K/`, `docs/results/SP-E5-04K.md`.

Отчёт: docs/results/SP-E5-04K.md; остаток указать по пунктам.
