# SP-E1-01F · Безопасное восстановление пароля

Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: Перевести отправку ссылок восстановления пароля на надёжную контролируемую очередь без прямого legacy SMTP-флага. Сохранить одинаковый публичный ответ для существующего и неизвестного адреса, ограничение частоты, срок и одноразовость токена, отзыв сессий после смены. Исключить токены/адреса из логов и не повторять автоматически неопределённую доставку.

Зависимости (нужен done после merge): SP-E0-04, SP-E1-01D.
Область записи: app/Shell/Auth/Http/PasswordController.php, app/Shell/Auth/PasswordDelivery/, config/features/E1-01F.php, database/migrations/E1-01F/, docs/modules/E1-01F.md, tests/Feature/Shell/Auth/PasswordDelivery/, tests/Unit/Shell/Auth/PasswordDelivery/, docs/results/SP-E1-01F.md.
Экраны: инфраструктура/контракты.
Источники: E1-01.
- E1-01: ТЗ S-0.1–S-0.5, S-0.7 стр. 236–270; макеты 1–6, 11; R-S-14

Приёмка:
- Тесты существующего/неизвестного адреса, срока, использования токена, повторов, отзыва сессий, fake SMTP и безопасных логов; никакой реальной отправки.

Дополнительно: До завершения не включать legacy AUTH_EMAIL_DELIVERY_ENABLED: он отдельно управляет прямой отправкой сброса пароля.
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/SP-E1-01F.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.

## Материалы

Исходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.
Полный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.
