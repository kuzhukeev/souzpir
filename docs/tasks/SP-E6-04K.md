# SP-E6-04K · Действия бота: журнал вызовов и выключатели в ходе

**Зачем:** приёмка экрана D-5.7 после повторного аудита 08.11.2026 (последние частичные экраны).
**Зависимости:** SP-E6-04G, SP-E6-04J.

## Приёмка
- Решение 08.11.2026 (по поручению владельца): прежнее «новых журналов не вводить» из SP-E6-04G снято для этой карточки. Добавить append-only таблицу журнала вызовов действий (organization_id, brand_id, dialog_id, tool, status, created_at); аргументы, тексты и контакты клиента не хранить.
- Запись — в одной точке выполнения действия (ToolRunner / ConfiguredToolHandler) для всех девяти действий, включая отказы (disabled, unavailable, invalid_arguments); повтор того же хода (retry/replay) не удваивает счётчик — ключ идемпотентности по ходу и действию.
- D-5.7: ToolSettingsController считает «Вызовы за 30 дней» по журналу для всех действий в рамках текущего бренда; для create_promise и escalate сохраняется совместимость с прежними источниками за период до появления журнала. «нет данных» — только когда за 30 дней нет ни одной записи; значения не выдумывать.
- D-5.7: выключатели экрана применяются в полном ходе: выключенное действие ToolRunner не выполняет (status disabled, ход продолжается без него), обязательные действия выключить нельзя. Если исключить действие из allowedTools можно только правкой Policy.php или ApiOpeningInput.php (scope SP-E3-07G) — не трогать, записать остаток в отчёт.
- Записанный replay и equivalence-тесты не меняются (fake AI, живые вызовы выключены). Feature-тест: запись, бренд-изоляция, период 30 дней, идемпотентность, выключатель; browser-тест D-5.7 со значениями у всех действий на 1440/1024/390.

Scope: `app/Seller/Engine/ToolRunner.php`, `app/Seller/Engine/Tools/`, `app/Seller/Runtime/ConfiguredToolHandler.php`, `app/Seller/Settings/Tools/`, `database/migrations/E6-04K/`, `resources/js/pages/Seller/Settings/Tools/`, `tests/Feature/Seller/Settings/Tools/ToolJournalE604KTest.php`, `tests/Browser/E6-04K/`, `docs/results/SP-E6-04K.md`.

## Остаток 08.11 (частичная приёмка)
- `FirstTurnCoordinator.php:196-199` и `SellerFollowup/FollowupCoordinator.php:313-315` передают `$claim->conversationId`/`$claim->turnId` в `ToolRunner::run` и `calculateDelivery`; `ToolRunner` пишет журнал только при заданном turnId (убирает запись из `CatalogController::stock`, завышающую check_stock на D-5.7). Тест: повтор хода не удваивает счётчик calc_delivery/check_stock.

Scope дополнен: `app/ProtectedOperations/FirstTurnCoordinator.php`, `app/ProtectedOperations/SellerFollowup/FollowupCoordinator.php`.
