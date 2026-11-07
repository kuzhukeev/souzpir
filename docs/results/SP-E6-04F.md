Модель: Sonnet 5

# SP-E6-04F — передача человеку: runtime настроек

- `MonitorHandoffs` (команда `seller:monitor-handoffs`) подключила три сохранённых поля из SP-E6-04C к runtime, не трогая прежнее поведение таймера (проверено: прежняя unconditional-задача `seller_tasks(type=reminder)` создаётся как раньше):
  - «Уведомление» → `NotificationDispatcher`/`outbox_events`, событие `seller.conversation.handed_off` (reason=timeout, task_id — id записи idempotency), по уже существующему контракту `docs/contracts/registry.json` и матрице `CompanyNotificationDispatcher`/`NotificationProcessor`.
  - «Напоминание» + непустая «Запасная фраза» → одно agent-сообщение клиенту в диалоге через `FakeChannelDelivery` (как в `FollowupSender`), `delivered_at` проставляется.
  - Обе настройки независимы, срабатывают один раз на occurrence (та же idempotency-запись `seller_handoff_reminders`, что и у прежнего таймера).
- `Handoff.tsx`: ссылка «Правила распределения →» ведёт прямо на `/seller/settings?tab=assignment` (маршрут с вкладкой уже существует, вшита в `Schedule/Index.tsx`); убрана устаревшая подсказка «на открывшейся странице выберите…»; обновлён текст про подключение настроек.
- Тесты: `HandoffTimeoutRuntimeE604FTest` (3 теста, новый файл) — уведомление+фраза срабатывают один раз, оба выключателя не дают побочных эффектов (внутренняя задача всё равно создаётся), изоляция бренда. `ToolSettingsTest`, `HandoffE604CTest` и остальные `tests/Feature/Seller/Settings/Tools/*`, `tests/Feature/Seller/Settings/Schedule/*` — зелёные (кроме `ToolSourcesE604JTest`, падает по инфраструктурной причине слота «dedicated test database owner», не из-за правок, не в scope). Playwright `tests/Browser/E6-04C/handoff.spec.ts` обновлён под новую ссылку/текст и зелёный (эта карточка — прямое продолжение SP-E6-04C, фикстура общая). `npm run lint/typecheck/test/build`, `composer lint` — зелёные.
- Макет: `D-5.3-handoff-routing.webp` (`mockups D-5.3`) — состав экрана совпадает; скриншот `.local/screenshots/SP-E6-04F/handoff.png`, светлая тема 1440.

## Техдолг
- Нет новых; существующий пункт про общую очередь уведомлений для `DialogTransferController`/`DialogTaskController` — отдельно в SP-E5-09, не затронут.

## Принято по умолчанию
- Обе новые настройки независимы от прежнего внутреннего таймера-напоминания: он остаётся безусловным (решение SP-E6-04C «работа таймера не меняется»), «Уведомление»/«Напоминание» добавляют новые эффекты поверх, а не заменяют старый.
- `task_id` в событии — id внутренней идемпотентной записи таймера (единственный стабильный идентификатор на occurrence), не id видимой задачи менеджера.

## Нужно от интегратора
- Нет.
