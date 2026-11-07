# SP-E1-13B · Завершить ограничения тарифов

**Зачем:** завершить требования по решению владельца.
**Часть чего:** остаток SP-E1-13, docs/results/SP-E1-13.md.
**Зависимости:** SP-E1-13.

## Приёмка
- Защитить общий вход первого хода и остальные канальные входы при исчерпании лимита.
- Обеспечить ограничения числа участников и каналов в соответствующих модулях.
- Завершить экран Vision и подписи будущих моделей Yandex согласно решению владельца.

Остаток записать в отчёт.

Scope: `routes/features/C-02.php`, `app/Shell/Plans/`, `app/Shell/Organizations/`, `app/Seller/Channels/`, `app/Vision/Settings/`, `app/Vision/Measurements/`, `resources/js/pages/Vision/Settings/`, `resources/js/pages/Vision/Measurements/`, `tests/Feature/Shell/`, `tests/Feature/Vision/Settings/`, `tests/Feature/Vision/Measurements/`, `tests/Browser/E1-13B/`, `docs/results/SP-E1-13B.md`.

Уточнение интегратора 07.11: защиту входа `/seller/turns/first` делать на маршруте (`routes/features/C-02.php`) через `app/Shell/Plans/LimitEnforcer`, `app/ProtectedOperations/` не трогать; shared-ai-stages не нужен.

## Решение владельца 06.11.2026 — общие AI-стадии

Решение владельца 06.11: разрешены только совместимые добавления общих AI-стадий без рефакторинга и без изменения существующего поведения и записанного replay; проверить права, изоляцию брендов и повторы. Только fake AI; живые и платные вызовы выключены. Общий ресурс shared-ai-stages занимает одна карточка до слияния. Прежние ограничения «до решения владельца» считать снятыми в этих границах.
