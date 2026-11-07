Модель: Sonnet 5.5
# SP-E5-02C — сессия и переписка виджета

- Остаток 07.11.2026 (после SP-E5-03F): диалог виджета открывается в режиме `bot` (кроме расписания "off" — `manager`, как раньше). Ход визитёра идёт через **тот же** защищённый pipeline, что API/session-каналы — `FollowupCoordinator::orchestrateTurn` → extractor/responder/guard (fake), `FollowupFinalizer` — без обхода runtime прямой записью сообщений. `ApiFollowupInput::extractorLocked` расширен веткой `source==='public'` (виджет): проверка активной `widget_sessions` вместо токена/сессии сотрудника, привязка к турну по префиксу `widget:`. Весь ход выполняется внутри `TenantContextRunner::run` (нужно моделям с global tenant scope — knowledge/prompt sets).
- Приветствие канала «Сайт» с `{страница}`/`{имя}` (имя контакта, по умолчанию «гость») добавляется к первому ответу бота (version 0) через существующий `GreetingResolver::resolve('widget', ...)` — проверено тестом с настроенным `greetings.widget_catalog`.
- `/widget/message` возвращает ответ бота в `reply` и он сразу отображается в чате виджета (своя мини-лента сообщений в панели); повтор с тем же `idempotency_key` отдаёт тот же `reply` без повторного AI-хода.
- `FollowupCoordinator::orchestrateTurn`/`FollowupFinalizer::finishProtectedApi` — добавлен опциональный `?string $page` (обратная совместимость сохранена, проверено существующими тестами API/session/job-каналов).
- Тесты: `tests/Feature/Seller/Channels/Widget/*` 12/12 (`WidgetSessionTest` — bot-ход 201 с `reply`, повтор идемпотентен и возвращает тот же `reply`, недоступный `ai_driver` → 503, чужой токен 404, пустой текст 422, изоляция по бренду, 2 сообщения в БД); `tests/Browser/E5-02/*` 5/5 (добавлен `widget-session.spec.ts` в `playwright.config.ts` testMatch — раньше не запускался; добавлена проверка видимого ответа бота); регрессия — `FollowupHttpTest`, `GreetingFirstTurnTest`, `TurnHttpTest`, `ProtectedSimulationRuntimeTest`, `SimulationBridgeE306DTest` 14/14. `check frontend` rc=0, Pint — зелёные.
- Скриншот `.local/screenshots/sp-e5-02c/widget-chat-1440.png`, светлая тема, 1440: видны реплика визитёра и ответ бота.

## Техдолг
- Автооткрытие виджета по `behavior.delay_seconds` остаётся статичным текстом приглашения (`data.behavior.invitation`), не запускает реальный AI-ход без клиентского текста — "написать первым" для виджета требует `ApiOpeningInput`/лид-абстракции API-канала (чужой модуль, вне scope).
- Тег fewshot `open_greeting_widget` не подключён к ходу визитёра (Policy.php хардкодит `open_greeting` вне scope); на практике не влияет — первый ход визитёра идёт как обычный `answer_and_ask`/`ask_slot` по реальному тексту, а не как системный `intro_and_ask`, поэтому слово «заявка» не появляется.
- У widget-сессий нет фоновой очистки по истечении срока (из прежней сдачи).

## Принято по умолчанию
- Срок сессии — 24 часа (из прежней сдачи).
- Первый визит создаёт bot-диалог (а не manager); первая реплика визитёра обрабатывается как обычный ход (а не искусственный "intro_and_ask" без текста) — это совпадает с установленным в кодовой базе паттерном для других каналов (`FirstTurnGoal` передаёт `isFirstTurn=false` для реального первого сообщения клиента; `isFirstTurn=true` зарезервирован только для исходящего "write first" без текста).
- Приветствие канала передаётся через готовый `GreetingResolver` (как у других каналов), а не через Policy/Goal.

## Нужно от интегратора
- Подтвердить/перенести в decisions-pending: AI-проактивное автооткрытие виджета без клиентского текста ("написать первым" для публичной сессии) требует допуска в scope `app/Ai/Protected/ApiOpeningInput.php` или дублирования лид-абстракции — решить в отдельной карточке.
