# SP-E5-04S

Код карточки влит ранее: подсказка менеджеру «Предложить ответ» (D-3.3; extractor→responder→guard через fake Gateway, `POST /seller/dialogs/{dialog}/suggestion`) — коммит 3d7493b1; метка «Прочитано» в ленте и треде (D-2.1/D-2.2, `conversations.read_sequence/read_at`) — коммит 8f2a1a0f (merge 04f3e1b2).

Эта сдача — последний пункт «Остатка»: браузерный тест `tests/Browser/E5-04S/`.
- `read-marker.spec.ts` + Vite-фикстура (сервер на свободном порту в beforeAll): у прочитанного входящего «✓ Прочитано · ЧЧ:ММ», у непрочитанного — нет; в ленте бейдж «Непрочитано» у диалога с новым входящим; кнопка «Предложить ответ» доступна назначенному менеджеру.
- Скриншот: `.local/screenshots/SP-E5-04S/dialog.png` (светлая тема, 1440).

Проверено:
- `npx playwright test tests/Browser/E5-04S` — 1 passed.
- `php artisan test` ReadMarkerE504STest, ManagerSuggestionControllerE504STest, ManagerSuggestionGatewayE504STest, ManagerSuggestionSourceE504STest — 8 passed (91 assertions): сценарий, права, изоляция бренда, ошибки/устаревшая история.
- `check lint` — прошёл.
- Сверено с макетами `40/14-D-2.2-dialog.webp`, `39/141dce5e-D-2.1-dialogs.webp`; аудит `screens-audit-0711-2224.json` D-2.1 gap «нет метки «Прочитано»» закрыт коммитом 8f2a1a0f.

## Остаток
- Нет. Живая оценка диалога моделью вне карточки (ждёт решения владельца).

## Принято по умолчанию
- «Прочитано» — открытие диалога сотрудником, а не прочтение клиентом (вебхуков прочтения от каналов нет).
