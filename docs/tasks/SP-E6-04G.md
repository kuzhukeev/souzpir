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
