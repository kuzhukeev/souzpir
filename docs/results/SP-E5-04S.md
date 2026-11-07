# SP-E5-04S

Ранее сдано (влито): экран «Предложить ответ» для менеджера (D-2.1/D-2.2/D-3.3) — extractor→responder→guard через fake Gateway, маршрут `POST /seller/dialogs/{dialog}/suggestion`, кнопка в тулбаре. См. историю коммитов; тесты `ManagerSuggestionControllerE504STest` и соседние не изменялись.

Эта сдача — остаток аудита: метка «Прочитано» у входящих сообщений (D-2.1/D-2.2).

- `database/migrations/E5-04S/...add_read_sequence_to_conversations.php` — `conversations.read_sequence`/`read_at`. Не трогал `messages`: триггер `c02_message_history_revision` бьёт `conversations.history_revision` на любой UPDATE строки `messages` (в т.ч. только `read_at`), что ломает snapshot-проверки готовых подсказок/ходов (`ReadySuggestions`, `prepareFinalRound`). Поэтому прочитанность — на уровне диалога (`read_sequence` сверяется с `messages.history_sequence`, которая уже вела этот трекинг), новые колонки вне списка, отслеживаемого триггером.
- `app/Seller/Dialogs/DialogsController.php` — при открытии конкретного диалога (`GET /seller/dialogs/{dialog}` и `.../mine/{dialog}`) продвигает `read_sequence` до текущего `history_sequence`.
- `app/Seller/Dialogs/DialogReader.php` — в ленте `unread` (последнее сообщение от клиента и `history_sequence` > `read_sequence`); в треде диалога `message.read_at` для сообщений клиента, прочитанных на момент открытия.
- `resources/js/pages/Seller/Dialogs/Index.tsx` — бейдж «Непрочитано» и полужирное выделение в ленте; под сообщением клиента «✓ Прочитано · ЧЧ:ММ» в треде (как `deliveryLabel` для менеджера).

Проверено:
- `php artisan test tests/Feature/Seller/Dialogs/ReadMarkerE504STest.php` (2 теста): открытие помечает входящие прочитанными и это видно в ленте/треде; изоляция бренда (чужой диалог не трогается).
- `php artisan test tests/Feature/Seller/Dialogs/` — 36/37 (красный только `PendingPageE504HTest`, падает и без моих правок — см. Техдолг).
- `npm run build`, `composer lint` (pint) — прошли.
- Сверено с `D-2.1-dialogs.webp`/`D-2.2-dialog.webp`: метка «✓ Прочитано · время» под сообщением по стилю макета.

## Остаток
- Нет Playwright-теста `tests/Browser/E5-04S/` — браузерный драйвер недоступен в окружении; поведение проверено feature-тестами и сборкой.

## Техдолг
- `PendingPageE504HTest` красный независимо от этой карточки: `TenancyTestCase` требует «изолированную» тестовую роль БД (`rolsuper`/`rolcreatedb` = false), в этом слоте роль привилегированная — окружение, не код.

## Принято по умолчанию
- «Прочитано» — факт открытия диалога сотрудником (любым с правом `seller.dialogs.read`), а не доставка/прочтение клиентом: в мокапе чек-марка похожа на read-receipt клиента, но у нас нет вебхуков прочтения от каналов (fake-драйверы), а карточка прямо требует `read_at` от открытия диалога менеджером.
