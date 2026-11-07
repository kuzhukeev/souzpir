Модель: Sonnet 5

# SP-E6-01E — служебные фразы reminder/goodbye к напоминаниям клиенту

Продолжение после приёмки 07.11 (error/handoff/no_answer/out_of_hours/opt_out — влито ранее). В этой части: `ServicePhraseSelector::customForEvent()` подключён к `PrepareFollowups` (`app/Seller/Followups/`) для текста напоминаний клиенту о заявке.

Сделано: при формировании `seller_followup_candidates` для причины `silence` — если у бренда явно сохранена фраза `reminder`, она заменяет шаблонный текст напоминания (кастомные `texts` настройки / fewshot-фолбэк / дефолтный текст — не трогаются, если фраза не сохранена); на последнем разрешённом по `max_auto` напоминании (sequence === max_auto) та же логика применяется к фразе `goodbye` вместо `reminder` — естественная точка «прощания» перед тем, как бот прекращает напоминать. Причина `next_contact_at` (договорённый контакт, `agreed_text`) не трогается — это отдельная настройка. Только явно сохранённая фраза, без дефолтов — старое поведение без сохранённой фразы не меняется.

Проверки: новый `tests/Feature/Seller/Followups/ReminderGoodbyePhraseE601ETest.php` — 3 теста, 9 проверок (reminder-override до последнего, goodbye-override на последнем, без сохранённой фразы — шаблон как раньше; через мок `KnowledgeRepository`, brand-scoped `withArgs`). Регрессия модуля: `tests/Feature/Seller/Followups/` — все 25 тестов зелёные (231 проверок), включая изоляцию бренда и прав (`AgreedTimeE603KTest`, `FollowupsScreenTest`). Регрессия runtime: `ServicePhraseSelectorTest`, `ServicePhraseOverrideE601ETest`, `QuotaRuntimeE113Test`, `ReactionRuntimeE603VTest`, `FirstSellerTurnHttpTest` — зелёные без изменений (10 тестов). `check lint` — composer validate ok, покрытие suites ok, Pint ok. Без живых вызовов (`Http::preventStrayRequests`).

## Остаток
- `error` — не подключена и в этой части: даже в границах `app/Seller/Followups/` у сбоя отправки (`FollowupSender` try/catch) нет клиентского текста — ход просто не завершается, кандидат остаётся `pending` для повтора; детерминированной точки «ход завершился без текста» с видимым клиенту сообщением в scope нет. Нужна отдельная карточка с явным решением, где именно вставлять фразу `error` (кандидат на `app/Seller/Engine/` или новый hook в `FollowupSender`/`FirstTurnCoordinator`).

## Техдолг
- Нет новых рисков сверх указанного выше ограничения по `error`.

## Принято по умолчанию
- `goodbye` применяется к последнему разрешённому автонапоминанию (`sequence === max_auto`, причина `silence`), а не к отдельному новому триггеру — в коде нет события «диалог завершён после напоминаний», а прототип ТЗ не уточняет момент; это минимальная точка в существующем потоке без нового поведения по умолчанию (используется только при явно сохранённой фразе).
