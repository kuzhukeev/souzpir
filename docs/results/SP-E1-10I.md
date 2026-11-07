# SP-E1-10I — доработка 3: реальная доставка общих уведомлений за флагом (S-5.3)

- S-5.3, последний незакрытый пункт — «внешняя доставка включена через очередь с fake-транспортом в тестах»: Telegram-подключение в три шага и чипы почты уже были сделаны ранее (`8a4aecf7`). Добавлена реальная доставка `company_notification_deliveries` за флагом `features.E1-10.defaults.company_notification_delivery` (env `COMPANY_NOTIFICATION_DELIVERY_ENABLED`, по умолчанию выключен) — по образцу `WebhookDelivery`/S-5.2: `CompanyNotificationDelivery::send()` шлёт письмо через `Mail` (переиспользован `NotificationMail`) и Telegram через `Http` на Bot API (`features.E0-04.defaults.telegram_bot_token`), с проверкой, что настройка канала и адрес всё ещё актуальны; `DeliverCompanyNotification` — job-обёртка. `CompanyNotificationDispatcher::enqueue()` при включённом флаге создаёт доставки и диспетчит их независимо от `runtime.notification_driver`; при выключенном — прежнее поведение (только в fake-режиме) не изменилось. UI (`Notifications.tsx`) скрывает предупреждение «доставка выключена» и получает новый статус `sent` в журнале, когда флаг включён (проп `company_notification_delivery_available`).
- D-10.2 (ранее в «Остатке») проверено, а не переделано: карточки amoCRM/Bitrix24 уже стоят рядом с сопоставлением в двухколоночной сетке, поле EYON — `<select>`, возврат после создания вебхука идёт через `WebhookConfigurationBoundary` (`return_to=crm`) без секрета в URL — всё сделано в `b2fddff2`. Доказательство: `tests/Feature/Seller/Channels/Crm/` (12/12, включая `CrmReturnE110ITest`) и `CrmWebhookTileE110HTest` — зелёные.
- Проверено: `tests/Feature/Shell/Integrations/CompanyNotificationQueueE110JTest.php` — 4/4 (добавлены 2 новых теста: реальная отправка email+Telegram с подтверждением статуса `sent`, отмена при расхождении настроек); старый тест «отключено без fake-драйвера» не менялся и остался зелёным. `check lint`, `check frontend` (lint/typecheck/vitest/build) — зелёные.
- Сверено с макетами `mockups S-5.3` (`dfc0fb81-S-5.3-company-notifications.webp`, `642de096-S-5.3-telegram-connect.webp`) и `mockups D-10.2` — расположение не менялось, визуальных отличий от макета нет.

## Остаток
- S-7.1 (SP-E1-08E): тихие часы и ежедневная сводка с группировкой по бренду/пользователю/каналу — не начато, крупная отдельная доработка.
- Скриншоты не прикладываю: правка серверная и за выключенным по умолчанию флагом, видимый экран не изменился (кроме исчезающего предупреждения при включённом флаге, который в MVP выключен).

## Техдолг
- `tests/Feature/Shell/Integrations/SecretSecurityTest.php`, `PruneSecretReceiptsTest`, `SecretSharedSessionRaceTest`, `Webhooks/WebhookCommitTest` по-прежнему не выполняются в этом слоте — нужна непривилегированная роль БД (`IsolatedDatabaseMigrations`), настраивает только `scripts/ci/postgres.sh`; не связано с этой правкой.

## Принято по умолчанию
- Реальная доставка общих уведомлений включается отдельным флагом `COMPANY_NOTIFICATION_DELIVERY_ENABLED` (по умолчанию `false`), а не общим `NOTIFICATION_DRIVER` — так же, как вебхуки в S-5.2; владелец подключает боевые адреса и токен бота отдельно.
