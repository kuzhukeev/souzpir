# SP-E5-04S · Защищённая подсказка менеджеру

**Зачем:** завершить экран по принятому результату.
**Часть чего:** остаток SP-E5-04R, docs/results/SP-E5-04R.md.
**Зависимости:** SP-E5-04R.

## Приёмка
- Реализовать защищённое происхождение и сохранение manager-подсказки, подключить extractor/responder/guard и gateway, проверить повторы, ошибки, права, бренд и полный fake-сценарий; без живых вызовов. Изменение общих AI-стадий предварительно согласовать с владельцем.
- Проверить права, изоляцию брендов и ошибки; незавершённое указать в «Остаток».

Scope: `app/Seller/Dialogs/`, `app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/ProtectedOperations/`, `resources/js/pages/Seller/Dialogs/`, `tests/Feature/Seller/Dialogs/`, `tests/Feature/ProtectedOperations/`, `tests/Browser/E5-04S/`, `docs/results/SP-E5-04S.md`.

## Решение владельца 06.11.2026 — общие AI-стадии

Решение владельца 06.11: разрешены только совместимые добавления общих AI-стадий без рефакторинга и без изменения существующего поведения и записанного replay; проверить права, изоляцию брендов и повторы. Только fake AI; живые и платные вызовы выключены. Общий ресурс shared-ai-stages занимает одна карточка до слияния. Прежние ограничения «до решения владельца» считать снятыми в этих границах.


## Остаток 07.11.2026 19:00 (реестр экранов: D-2.1, D-2.2, D-3.3 «Предложить ответ»)
- Подсказка менеджеру в диалоге по-прежнему «Генерация подсказки для этого режима пока недоступна», кнопка disabled (resources/js/pages/Seller/Dialogs/Index.tsx). Выполнить три пункта остатка отчёта SP-E5-04S: extractor → состояние/цель → защищённые responder/guard через fake Gateway; хранение подсказки с привязкой к сессии, истории и версии знаний с повторной проверкой прав; подключить кабинетный маршрут (`routes/features/E5-04.php`) и показать загрузку/ошибку/готовый текст и «Использовать подсказку» для режима менеджера.
- Scope сужен: вместо `app/ProtectedOperations/` — `app/ProtectedOperations/SellerFollowup/` (mode suggest в FollowupCoordinator). Пока SP-E5-02C держит SellerFollowup/ и ApiFollowupInput.php — карточка ждёт; не трогать чужие изменения.

## Остаток 07.11.2026 (аудит экранов, интегратор)
- D-2.1/D-2.2: метка «Прочитано» у входящих сообщений (read_at при открытии диалога менеджером, миграция `database/migrations/E5-04S/`), показ в ленте и в диалоге по макету; feature-тест, права, бренд.
- Живая оценка диалога моделью (вместо fake judge) — расход денег, ждёт «да» владельца в docs/decisions-pending.md, в карточку не входит.
- Scope сужен 07.11: `app/Seller/Dialogs/`, `resources/js/pages/Seller/Dialogs/Index.tsx`, `routes/features/E5-04.php`, `database/migrations/E5-04S/`, `tests/Feature/Seller/Dialogs/`, `tests/Browser/E5-04S/`, `docs/results/SP-E5-04S.md` (`app/Ai/Protected/`, `app/Ai/Gateway.php`, `app/ProtectedOperations/` заняты другими карточками).

## Сужение scope 07.11.2026 23:30 (планировщик)
- `routes/features/E5-04.php` занят SP-E5-04N; новые маршруты (подсказка менеджеру, метка «Прочитано») — в отдельном файле `routes/features/E5-04S.php` (файлы routes/features/*.php подхватываются FeatureRegistry автоматически).



## Остаток 08.11.2026 (аудит экранов, планировщик)
- D-2.3: в шапке открытого диалога (`Index.tsx`, строка `{dialog.channel} · {dialog.stage}`) показываются коды `widget · open` — выводить русские подписи канала (уже есть `channelNames`), стадии и итога (как в списке/фильтрах этого экрана); неизвестный код — как есть.
- Проверка: Playwright `tests/Browser/E5-04S/` (шапка без кодов). Остальные пункты карточки закрыты; scope сужен до этого файла.

Scope: `resources/js/pages/Seller/Dialogs/Index.tsx`, `tests/Browser/E5-04S/`, `docs/results/SP-E5-04S.md`.
