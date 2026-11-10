# SP-E5-04S

Код карточки влит ранее: подсказка менеджеру «Предложить ответ» (D-3.3; extractor→responder→guard через fake Gateway, `POST /seller/dialogs/{dialog}/suggestion`) — коммит 3d7493b1; метка «Прочитано» в ленте и треде (D-2.1/D-2.2, `conversations.read_sequence/read_at`) — коммит 8f2a1a0f (merge 04f3e1b2); браузерный тест — коммит 75ac6f03.

Эта сдача — остаток D-2.3 (аудит 08.11, scope сужен до `resources/js/pages/Seller/Dialogs/Index.tsx`): в шапке открытого диалога вместо кодов (`widget · open`) теперь русские подписи канала/стадии/итога — `channelNames`/`stageNames` (уже были в файле) и новый `outcomeNames` (те же пары, что в фильтре «Итог»); неизвестный код выводится как есть (fallback `|| raw`).

Проверено:
- `npx playwright test tests/Browser/E5-04S` — 1 passed; фикстура обновлена на реалистичные коды (`channel:'widget'`, `stage:'open'`), добавлена проверка шапки `Виджет · Новый` (без кодов).
- `npx tsc --noEmit`, `npx eslint resources/js/pages/Seller/Dialogs/Index.tsx` — без ошибок.
- `npm run build` — собралось; `check lint` (composer validate, test-suite coverage, pint) — прошёл.
- Правки только во фронтенд-файле, backend-тесты диалогов не трогались и не перезапускались.

## Остаток
Нет.

## Техдолг
Нет новых пунктов.
