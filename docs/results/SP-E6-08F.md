# SP-E6-08F — частичная реализация

- Разрешения цен и скидок сохраняются отдельно для бренда; интерфейс сообщает, что использование их в ответах бота ещё не подключено.
- В `check_stock` добавлен признак пустого результата и актуальное runtime-описание; golden-схема прототипа сохранена.
- Для списка каталога добавлены состояние загрузки и ошибка с повторной попыткой.
- Проверки: 10 PHP-тестов, 160 проверок; TypeScript, ESLint, Pint и 2 Playwright-теста в Chrome — пройдены.
- Скриншот: `.local/runtime/e6-08f-catalog.png` (1440, светлая тема).

## Остаток
- D-10.1: подключить согласованные цены и скидки к ответам и Guard, ограничив разрешённые числовые факты выбранными товарами.
- D-10.1: безопасная fake-проверка отправки прайса через пользовательский экран.
- D-10.4: проверить реальный защищённый runtime целиком, включая fallback на менеджера при отсутствии товара.

## Техдолг
- Нет.

## Принято по умолчанию
- До подключения runtime сохранение разрешений не обещает, что бот уже называет цены и скидки.

## Нужно от интегратора
- Scope защищённого runtime: `app/ProtectedOperations/FirstTurnCoordinator.php`, `app/ProtectedOperations/FirstTurnDeterministicGuard.php`, `app/ProtectedOperations/FirstTurnTextFinalizer.php`, `tests/Feature/ProtectedOperations/`; он пересекается с активной SP-E5-03C. Также потребуются `app/Seller/Engine/ResponderMessages.php`, `app/Seller/Engine/ResponderRequest.php`, `app/Seller/Engine/DeterministicGuard.php` и тесты `tests/Feature/Seller/Engine/`.
- SP-E1-01I ожидает `app/Seller/Knowledge/Facts/FactsController.php`, который мы не изменяли; можно отдельно согласовать сужение scope.
- `PriceMailSender` способен открыть SMTP: тестовый UI-путь должен гарантировать fake без вызова живого транспорта. Автоматическая проверка отклонила добавление POST-ветки с вызовом `ToolRunner::run('send_price')`: не доказано, что пользовательский путь исключает реальную отправку письма.
- Интегратор оформит карточку-продолжение с перечисленным остатком до merge.
