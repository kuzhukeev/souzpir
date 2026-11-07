# SP-E6-04G · Инструменты полного защищённого хода

**Зачем:** завершить экран по решению владельца.
**Часть чего:** остаток SP-E6-04E, docs/results/SP-E6-04E.md.
**Зависимости:** SP-E6-04E.

## Приёмка
- Проверить полный защищённый ход и сохранение результата интеграционным тестом.
- Подключить реквизиты и оставшиеся реализованные действия с учётом настроек бренда и fake-транспорта.

Проверить права, бренд и ошибки; остаток записать в отчёт.

Scope: `app/ProtectedOperations/`, `app/Seller/Runtime/`, `app/Seller/Engine/Tools/`, `app/Seller/Settings/Tools/`, `tests/Feature/ProtectedOperations/`, `tests/Feature/Seller/Settings/Tools/`, `tests/Browser/E6-04G/`, `docs/results/SP-E6-04G.md`.

## Решение владельца 06.11.2026 — общие AI-стадии

Решение владельца 06.11: разрешены только совместимые добавления общих AI-стадий без рефакторинга и без изменения существующего поведения и записанного replay; проверить права, изоляцию брендов и повторы. Только fake AI; живые и платные вызовы выключены. Общий ресурс shared-ai-stages занимает одна карточка до слияния. Прежние ограничения «до решения владельца» считать снятыми в этих границах.

## Остаток 07.11.2026 (отчёт SP-E6-03L, планировщик)
- Tool `escalate` из `allowedTools` handoff-цели не реализован в `app/Seller/Engine/ToolRunner.php` (возвращает `unavailable`): реализовать как передачу человеку по настройкам бренда с fake-транспортом; покрыть Feature-тестом. Файл добавлен в scope.

## Сужение scope 07.11.2026 23:30 (планировщик)
- `app/Seller/Runtime/` сужен до `app/Seller/Runtime/ConfiguredToolHandler.php` (реквизиты и действия инструментов); остальной runtime занят активными задачами.
- `app/ProtectedOperations/` сужен до файлов, где вызываются инструменты: `app/ProtectedOperations/FirstTurnCoordinator.php`; `FirstTurnTextFinalizer.php` и `SellerFollowup/` заняты SP-E7-04M и SP-E5-04N.


## Остаток 08.11.2026 (планировщик, запуск 20261107-225307)
- D-5.7 (макет `mockups D-5.7`): колонка/строка описания у каждого действия бота по макету.
- «Вызовы за 30 дней» для всех действий, а не только `create_promise`: считать из уже существующих журналов вызовов инструментов (audit_logs/записи ToolRunner), где источника нет — «нет данных»; новых журналов не вводить.
- Состояния загрузки (skeleton) и ошибки сохранения/загрузки на экране; ширины 1440/1024/390, клавиатура, axe.
- Пункт «настройки применяются не в полном ходе» вне этой части (полный ход у SP-E4-01X).

Scope: `resources/js/pages/Seller/Settings/Tools/Tools.tsx`, `app/Seller/Settings/Tools/ToolSettingsController.php`, `tests/Feature/Seller/Settings/Tools/ToolCallCountsE604GTest.php`, `tests/Browser/E6-04G/`, `docs/results/SP-E6-04G.md`.
