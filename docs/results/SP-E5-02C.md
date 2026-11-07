Модель: Sonnet 5.5
# SP-E5-02C — сессия и переписка виджета

- Влита сохранённая ветка (сессия, согласие, origin/brand изоляция) с актуальным main (E5-01J manager-диалоги по контактной форме, generate-types).
- Добавлен `WidgetInboundRuntime`: ход виджета создаёт/переиспользует диалог канала «Сайт» (mode=manager), пишет сообщение через `InboundRecorder::ingest` (как Telegram), захватывает ход `ConversationRepository::claimTurn` и завершает его как `suppressed` (`SellerTurnFence`, тот же паттерн, что у `TokenInboundRuntime::suppress`/`FirstInboundRuntime::suppressManagerTurn`) — без AI-стадии, без прямой записи текста агента.
- Идемпотентность хода — через `PostgresTransactionalIdempotency` (ключ посетителя), повтор с тем же ключом возвращает тот же результат без повторной обработки.
- `/widget/contact` с session_token теперь только привязывает заявку к сессии (не создаёт второй диалог); без токена — старое поведение E5-01J.
- Исправлена ошибка в сохранённом `widget.js`: `crypto.randomUUID()` недоступен на http-страницах (не secure context) — ход сайта на http молча проваливался; добавлен fallback.
- Новая миграция `widget_sessions.conversation_id/contact_id`.
- Тесты: `tests/Feature/Seller/Channels/Widget/*` — 10/10 (включая WidgetSessionTest: сессия, ход 201, повтор идемпотентен, недоступный ai_driver → 503, чужой токен 404, пустой текст 422, изоляция по бренду); `tests/Browser/E5-02/widget-session.spec.ts` переписан на самодостаточный мок (без внешнего порта) — сессия/ход/повтор/недоступность; `widget-contact.spec.ts` — 4/4. node --check, Pint, composer lint — зелёные.
- Скриншот `.local/screenshots/sp-e5-02c/widget-chat-1440.png`, светлая тема, 1440, виджет-чат после успешного хода.

## Техдолг
- Ход виджета всегда уходит менеджеру (manager mode); AI-ответ (bot/intro_and_ask, персона) требует расширения `app/Ai/Protected/ApiFollowupInput.php` на источник публичной сессии — вне scope модуля этой карточки.
- У сессий нет фоновой очистки по истечении срока.

## Принято по умолчанию
- Срок сессии — 24 часа (из прежней сдачи).
- Первый визит сразу создаёт manager-диалог (без проактивного приветствия ИИ) — проактивный greeting/персона из широкого акцептанса владельца оставлены на будущую AI-карточку.

## Нужно от интегратора
- Решить, нужна ли для виджета AI-переписка (bot/suggest) в эту волну: требует изменения `app/Ai/Protected/ApiFollowupInput.php` (сейчас принимает только source token/session/job), который не входит в scope модулей этой карточки.
