# SP-E6-04G — остаток экрана D-5.7 «Действия бота»

Scope сужен планировщиком 08.11 до Tools.tsx/ToolSettingsController.php; предыдущая часть (tool `escalate`, коммит 7c4e1df0) уже на main — не трогал.

- Описание (строка-подзаголовок под каждым действием): добавлена константа `descriptions` в `Tools.tsx`, тексты по макету `mockups D-5.7-main`. Проверено: **mockups D-5.7-main.webp**.
- «Вызовы за 30 дней» для всех действий, не только `create_promise`: контроллер теперь считает и `escalate` (из `seller_tasks` `type=handoff` — единственный источник этого типа, пишет только `EscalateToManager`). Для остальных 7 действий источника вызовов в коде нет — остаётся «нет данных», новых журналов не вводил.
- Состояния загрузки/пусто/ошибки (макет `mockups D-5.7-states`): кнопка «Обновить» делает `router.reload({only:['callCounts','toolsLoadError']})`; skeleton (animate-pulse, `role=status`), ошибка (`role=alert` + «Повторить», `toolsLoadError` из контроллера при `QueryException`), пустое состояние («Данные о вызовах пока недоступны» + «Обновить», когда все счётчики null). Остальная форма (тумблеры, зоны, реквизиты) не блокируется — так проще и не ломает сохранение настроек.
- Проверено: **mockups D-5.7-settings.webp** — блок уже существовал (per-tool настройки), не трогал, вне диффа.

## Тесты
- `tests/Feature/Seller/Settings/Tools/ToolCallCountsE604GTest.php` (новый, 2 теста): счётчик `escalate` по бренду/30 дням + изоляция от другого бренда; `toolsLoadError=true` и `callCounts=[]` при `QueryException` на `audit_logs` (через `DB::listen`, без моков).
- `tests/Browser/E6-04G/tools.spec.ts` (новый, Playwright, свой Vite-сервер в beforeAll/afterAll): видимость описаний, skeleton при клике «Обновить» (перехват запроса через `page.route`), ошибка+«Повторить», пустое состояние. 1440/1024/390 — без горизонтального overflow (ad hoc проверка, не в составе набора). Скриншот `.local/screenshots/SP-E6-04G/tools-1440.png`.
- Регрессия: `tests/Feature/Seller/Settings/Tools/` — 9 прошли (включая существующий `ToolCallCountsE604HTest` для `create_promise`), 2 падают по известному техдолгу среды (`rolcreatedb=1` у роли слота, гейт `IsolatedDatabaseMigrations`, не из-за моих правок).
- `check lint` (composer validate/Pint, check-test-suite-coverage.py) и `tsc --noEmit` — зелёные.

## Остаток
- D-5.3/D-5.7 «настройки применяются не в полном ходе» — вне этой части (полный ход в SP-E4-01X), как указано в карточке.
- Экран не превращён в отдельную read-only таблицу «как на макете» — оставлен как объединённая форма настроек (список + тумблеры), т.к. переделка архитектуры страницы вне узкого scope.

## Техдолг
- Среда exec-9: роль БД слота с `rolcreatedb=1` не проходит гейт `IsolatedDatabaseMigrations` — не даёт локально гонять `ToolSourcesE604JTest`/аналоги (тот же техдолг, что в прежних отчётах SP-E6-03L/SP-E6-04G).

## Принято по умолчанию
- Счётчик `escalate` взят из `seller_tasks.type='handoff'` как единственного недвусмысленного источника; остальные типы (`reminder`, `promise`) не использовал для других тулов — они смешаны с нетул-источниками (напоминания о передаче по таймауту, ручные напоминания), что дало бы неверные цифры.
