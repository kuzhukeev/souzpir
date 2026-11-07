# SP-E1-09E · Имена объектов и экспорт журнала

**Зачем:** завершить экраны S-6.1 по решению владельца от 03.11.2026.
**Часть чего:** остаток SP-E1-09D из docs/results/SP-E1-09D.md.
**Зависимости:** SP-E1-09D (дождаться merge).

Завершить серверный реестр имён и ссылок объектов, читаемые фразы действий, меню и CSV с названиями и модулем.

Сверить с docs/sources/02b_mockup_journal.md; проверить сценарии, права, изоляцию брендов и ошибки. Без живых или платных вызовов в тестах.

Scope: `app/Shell/Journal/`, `resources/js/pages/Shell/Journal/`, `resources/js/features/Shell/Journal/`, `tests/Feature/Shell/Journal/`, `tests/Browser/E1-09E/`, `docs/results/SP-E1-09E.md`.

Отчёт: docs/results/SP-E1-09E.md; остаток указать по пунктам.


## Остаток 08.11.2026 (планировщик, запуск 20261107-225307)
- S-6.2 (макет `mockups S-6.2`, `74631d81-S-6.2-main.webp`, `ea7953ea-S-6.2-states.webp`): меню ⋮ в каждой строке (по образцу `JournalRowMenu` журнала действий), «Повторить» у ошибки остаётся.
- Статус отложенной доставки — «Отложено» (оранжевый), не «В очереди».
- Состояние ошибки загрузки по макету states (с кодом ERR_LOAD и «Повторить»).
- Только фронтенд; ширины 1440/1024/390, три темы, клавиатура, axe.

Scope: `resources/js/pages/Shell/Journal/Notifications.tsx`, `resources/js/features/Shell/Journal/shared.tsx`, `resources/js/features/Shell/Journal/JournalRowMenu.tsx`, `tests/Browser/E1-09E/`, `docs/results/SP-E1-09E.md`.
