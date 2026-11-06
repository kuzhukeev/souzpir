# SP-E6-10E · Поля клиентов в движке и диалогах

**Зачем:** завершить экран по решению владельца.
**Часть чего:** остаток SP-E6-10, docs/results/SP-E6-10.md.
**Зависимости:** SP-E6-10.

## Приёмка
- Применить дополнительные поля в сборе данных и карточке диалога, расширив фиксированные LeadState::SLOTS.
- Заполнить оптовые поля только для подтверждённого прототип-бренда; остальные начинают с пустого списка.
- Завершить оформление D-5.1 и просмотр опубликованной схемы.

Проверить права, бренд и ошибки. Остаток записать в отчёт.

Scope: `app/Seller/Engine/`, `app/Seller/Dialogs/`, `app/Seller/Settings/Policy/`, `resources/js/pages/Seller/Dialogs/`, `resources/js/pages/Seller/Settings/Policy/`, `tests/Feature/Seller/Engine/`, `tests/Feature/Seller/Dialogs/`, `tests/Browser/E6-10E/`, `docs/results/SP-E6-10E.md`.

## Остаток SP-E6-10C
- Остаток SP-E6-10C: обязательные поля до передачи и для стадии, карточка клиента, фильтры, labels.ts и Releases/Run.tsx; сверить экран с макетом.

Дополнительный scope: `app/Seller/Customers/`, `resources/js/pages/Seller/Customers/`, `resources/js/features/Seller/Customers/labels.ts`, `resources/js/pages/Seller/Knowledge/Releases/Run.tsx`, `tests/Feature/Seller/Customers/`.

Для защищённого runtime: pp/ProtectedOperations/, 	ests/Feature/ProtectedOperations/.

## Решение владельца 06.11.2026 — общие AI-стадии

Решение владельца 06.11: разрешены только совместимые добавления общих AI-стадий без рефакторинга и без изменения существующего поведения и записанного replay; проверить права, изоляцию брендов и повторы. Только fake AI; живые и платные вызовы выключены. Общий ресурс shared-ai-stages занимает одна карточка до слияния. Прежние ограничения «до решения владельца» считать снятыми в этих границах.
