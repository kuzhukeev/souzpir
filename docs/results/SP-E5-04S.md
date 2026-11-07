# SP-E5-04S

Завершён экран «Предложить ответ» для менеджера в диалоге (D-2.1/D-2.2/D-3.3), поверх уже готовой стадии extractor (`ManagerSuggestionSource`, `ManagerSuggestionExtractorInput`, `Gateway::completeManagerSuggestionExtractor`).

- `app/Seller/Dialogs/ManagerSuggestionResponderInput.php` — строит защищённый responder-запрос из ревалидированного результата extractor (goal/policy, промпт responder); без инструментов (подсказка — только текст).
- `app/Ai/Gateway.php` — добавлены `readManagerSuggestionExtractorLocked`, `completeManagerSuggestionResponder`, `readManagerSuggestionResponderLocked` по образцу существующих `ApiFollowup*` стадий (fake-only, `ai_calls`-идемпотентность).
- `app/Seller/Dialogs/ManagerSuggestionController.php` + маршрут `POST /seller/dialogs/{dialog}/suggestion` (`routes/features/E5-04.php`) — запускает extractor → responder → детерминированный Guard (`GuardDecision::finish(..., false)`, LLM-guard выключен по рантайм-конфигу), пишет audit `seller.dialog.suggestion.generated` внутри транзакции, возвращает текст; без сохранения как сообщения.
- `resources/js/pages/Seller/Dialogs/Index.tsx` — кнопка «Предложить ответ» в панели инструментов рядом с шаблонами (по макету D-2.1, а не отдельная панель, как было в черновике SP-E5-04R); по клику заполняет поле ответа текстом подсказки; ошибки — строкой над формой.

Проверено:
- `php artisan test tests/Feature/Seller/Dialogs/ManagerSuggestionControllerE504STest.php` (2 теста, 15 проверок): полный цикл extractor→responder→guard без отправки, идемпотентный повтор (2-й AI-вызов не создаётся повторно), audit-запись, конфликт при смене сообщения, изоляция бренда, отказ менеджеру не из назначенных.
- `php artisan test tests/Feature/Seller/Dialogs/ManagerSuggestionGatewayE504STest.php tests/Feature/Seller/Dialogs/ManagerSuggestionSourceE504STest.php tests/Feature/Seller/Dialogs/ReadySuggestionE504PTest.php` — старые тесты не сломаны (7/7).
- `php artisan test tests/Feature/Seller/Dialogs/` — 34/35 (не считая ранее известного флейки, см. Техдолг).
- `npx tsc --noEmit`, `npx eslint resources/js/pages/Seller/Dialogs/Index.tsx`, `./vendor/bin/pint --test` по изменённым файлам, `npm run build` — все прошли.
- Сверено с макетом `D-2.1-dialogs.webp` (расположение кнопки в тулбаре рядом с «Шаблоны фраз»/«Отправить»).

## Остаток
- Согласие из импорта CRM и виджета — не в scope этой карточки (отдельная, SP-E6-03AA).
- Нет Playwright-теста `tests/Browser/E5-04S/` и скриншота экрана — в этом окружении недоступен браузерный драйвер (`chromium-cli`); фронтенд проверен сборкой/типами/линтом, поведение — feature-тестами выше.

## Техдолг
- Подсказка менеджеру не предлагает инструменты (`calc_delivery`/`send_price`) и не повторяет попытку при отказе Guard — один проход, при отказе возвращается общий `unavailable`. Риск низкий (fake AI, текстовая подсказка).
- Независимо от задачи: уже влитый `ManagerSuggestionGatewayE504STest` (DatabaseMigrations без отката между тестами) периодически приводит к падению `tests/Feature/Seller/Dialogs/ReviewsE510*Test` при совместном прогоне директории — не вызвано этой карточкой, воспроизводится и без её изменений.

## Принято по умолчанию
- Кнопка «Предложить ответ» сразу подставляет текст в поле ответа (как в макете), без промежуточной карточки «подтвердить»/«использовать».
