# SP-E7-04O · Аналитика: контакты, PDF и расписание

**Зачем:** решение владельца 06.11.2026.
**Часть чего:** остаток SP-E7-04N, docs/results/SP-E7-04N.md.

## Приёмка
- Событие первого получения контакта; для старых диалогов «Нет данных». Серверный PDF бесплатной библиотекой с кириллицей; расписание сохранённых подписок с fake-транспортом, реальная доставка не подключена. Сверить оставшиеся пункты D-8.2 и D-8.6 с макетом.
- Основной сценарий, права и изоляция бренда, ошибки; без живых внешних вызовов. Остаток сдать по AGENTS.md.

Scope: `app/Seller/Analytics/`, `app/Seller/Conversations/`, `database/migrations/E7-04O/`, `routes/features/E7-04.php`, `resources/js/pages/Seller/Analytics/`, `tests/Feature/Seller/Analytics/`, `tests/Browser/E7-04O/`, `docs/results/SP-E7-04O.md`.
