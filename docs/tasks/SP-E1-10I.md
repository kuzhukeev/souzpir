# SP-E1-10I · Оболочка: доставка вебхуков, Telegram по коду, чипы адресов, внешняя доставка уведомлений

**Зачем:** решение владельца от 03.11.2026: задач должно хватать на 100 % закрытие экранов по функционалу; экраны S-5.2, S-5.3 остались без карточки после частичных сдач.
**Часть чего:** остаток после SP-E1-10 … SP-E1-10G (отчёт SP-E1-10G: «трёхшаговое подключение по коду… требуют отдельной задачи; внешняя доставка отключена»).
**Зависимости:** SP-R-01, SP-E1-03C, SP-E1-12C, SP-E1-10H (дождаться merge).

- Экран S-5.2 «API-ключи и вебхуки» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [27]): реальная доставка вебхуков: WebhookDelivery::available() возвращает true при настроенной очереди, DeliverWebhook отправляет подпись и тело, повторы и статус последней доставки показываются в таблице; IntegrationsController.php:87 больше не отдаёт 503 «недоступно»; в тестах — fake HTTP. Сделано ранее: ссылка на документацию API (Api.tsx:205, ApiDocumentation.tsx) — дополнить описанием всех публичных маршрутов, не только GET /api/v1/brand. Где сейчас: resources/js/pages/Shell/Integrations/Api.tsx:205; app/Shell/Integrations/IntegrationsController.php:87; app/Shell/Integrations/Webhooks/WebhookDelivery.php:19-21; app/Shell/Integrations/Webhooks/DeliverWebhook.php.
- Экран S-5.3 «Уведомления в почту и Telegram» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [28]): подключение Telegram по коду в три шага (модалка с кодом, ожидание, подтверждение) вместо поля chat ID (Notifications.tsx:78-79); адреса почты как чипы с валидацией вместо textarea (Notifications.tsx:86); внешняя доставка включена через очередь с fake-транспортом в тестах (Notifications.tsx:69). Сделано ранее: матрица «Общие уведомления», ссылка на личные уведомления. Где сейчас: resources/js/pages/Shell/Integrations/Notifications.tsx:69,78-79,86; app/Shell/Integrations/IntegrationsController.php:171-177.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.
- Сдача частями: если что-то из пунктов не сделано, в отчёте раздел «Остаток» перечисляет невыполненные пункты по экранам; интегратор принимает такой отчёт только вместе с заведённой карточкой-продолжением на этот остаток (правило в AGENTS.md).

Scope: `app/Shell/Integrations/`, `app/Shell/Notifications/`, `resources/js/pages/Shell/Integrations/`, `routes/features/E1-10.php`, `tests/Feature/Shell/Integrations/`, `tests/Browser/E1-10I/`, `docs/results/SP-E1-10I.md`.

Отчёт: docs/results/SP-E1-10I.md; локальные тесты задачи, submit и зелёный CI.
