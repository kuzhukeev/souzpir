Модель: Sonnet 5

# SP-E6-01E — D-6.3: drag-and-drop для «Быстрых ответов менеджера»

Остаток аудита 08.11 (служебные фразы reminder/goodbye и error/handoff/unknown_fact влиты ранее, см. историю коммитов). В этой части: `resources/js/pages/Seller/Settings/Persona/Index.tsx` — список «Быстрые ответы менеджера» (D-6.3).

Сделано: кнопки ↑/↓ заменены ручкой-грипом (нативный HTML5 drag-and-drop, `draggable` + `onDragStart/onDragOver/onDrop`, без новых npm-зависимостей) по макету `63/b729e1c4-D-6.3-service-phrases.webp`; перетаскивание переставляет шаблон в позицию сброса (`reorderReply`, splice). Для клавиатуры та же ручка: `Alt+↑`/`Alt+↓` двигает шаблон на одну позицию (`moveReply`, прежняя логика). Формат и сохранение `quick_replies` не менялись — `service.setData` тот же массив строк.

Проверено: `tests/Browser/E6-01E/reorder.spec.ts` — 3 теста (перетаскивание меняет порядок полей, Alt+стрелка двигает шаблон, кнопок ↑/↓ больше нет) — свой Vite-сервер на свободном порту в beforeAll/afterAll, фикстура переиспользует `tests/Browser/E6-01C/fixture.html`; скриншот 1440 светлая тема: `.local/screenshots/SP-E6-01E/quick-replies-reorder.png`. Регрессия: `tests/Feature/Seller/Settings/Persona/PersonaTest.php` — 3 теста, 90 проверок (права, изоляция брендов, валидация) — без изменений в PHP, формат не трогали. `check lint` (Pint/composer validate/suite coverage) и `check frontend` (eslint/tsc/vitest/build) — зелёные.

## Техдолг
- Нет новых рисков.

## Принято по умолчанию
- Клавиатурная альтернатива — `Alt+↑/Alt+↓` на самой ручке (а не кнопки внутри меню): так фокус остаётся на одном элементе и не плодит лишние интерактивные узлы в списке; в макете D-6.3 отдельного меню с перемещением нет.
