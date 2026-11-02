# SP-E1-08C · Завершить восстановление сессии после обрыва

**Зачем:** остаток SP-E1-08B по решению владельца: повтор потерянного ответа при переключении бренда или выходе должен вернуть прежний receipt и не оживить отозванную сессию.

**Часть чего:** SP-E1-08B добавила ключ и повтор переключения в текущей вкладке. Здесь остаются receipt после revoke, fencing поздних старых запросов, восстановление после reload и выход; после C остатка E1-08 нет, дальнейшей нарезки не делать.

Размер 1–3 часа. Зависимости: SP-E1-08B и SP-C-02B. Scope: `app/Shell/Auth/`, `app/Shell/Profile/`, `app/Tenancy/BrandSwitchController.php`, `app/ProtectedOperations/PostgresSessionIdempotency.php`, `resources/js/components/navigation/BrandSwitch.tsx`, `resources/js/pages/Shell/Profile/`, `tests/Feature/Shell/Profile/`, `tests/Feature/Tenancy/`, `tests/Feature/ProtectedOperations/`, `docs/results/SP-E1-08C.md`. Эти смежные файлы разрешены только для завершения пути receipt/revoke. Смену email и доставку сводки не менять.

Интегратор дополнительно разрешил для этой задачи `resources/js/pages/Shell/Auth/Ready.tsx`, `routes/features/E0-02.php`, `routes/features/E1-01.php`, `tests/Feature/Shell/Auth/LogoutReceiptTest.php` и `tests/Browser/E1-08/` через исключение в `scripts/task.py`. Нужно закончить ключ выхода и выдачу сохранённого receipt после отзыва на HTTP-маршрутах; не открывать доступ к приватным данным по отозванной сессии. Перед повторной сдачей влить актуальный `main`, проверить затронутый browser shard и Product.

Проверить потерю ответа, повтор после revoke, перезагрузку вкладки, выход и поздний старый запрос; результат не должен открывать или изменять данные после отзыва. Тесты с изолированной БД и fake/HTTP, без живых отправок. Не ослаблять существующие проверки; отчёт до 30 строк.
