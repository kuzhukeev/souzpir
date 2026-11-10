# SP-E6-03Z · Стадии и правило вопросов продавца

**Зачем:** решение владельца от 06.11.2026.
**Часть чего:** остаток SP-E6-03Y, docs/results/SP-E6-03Y.md.
**Зависимости:** SP-E6-03Y.

## Приёмка
- Цели и закрывающие поля только из сохранённых настроек бренда. Максимум один вопрос в сообщении бота и максимум два ответа бота подряд, заканчивающихся вопросом; третий даёт пользу без вопроса. Напоминания не учитываются и новых вопросов не задают. Проверить валидацию fake AI.
- Проверить права, изоляцию брендов, ошибочные и пустые состояния; только fake внешние вызовы.
- Решение владельца 06.11: разрешены только совместимые добавления общих AI-стадий без рефакторинга и без изменения существующего поведения и записанного replay; проверить права, изоляцию брендов и повторы. Только fake AI; живые и платные вызовы выключены. Общий ресурс shared-ai-stages занимает одна карточка до слияния.

Scope: `app/Seller/Settings/Policy/`, `app/Seller/Runtime/`, `app/Ai/Protected/`, `app/ProtectedOperations/`, `resources/js/pages/Seller/Settings/Policy/`, `tests/Feature/Seller/Settings/Policy/`, `tests/Feature/ProtectedOperations/`, `tests/Browser/E6-03Z/`, `docs/results/SP-E6-03Z.md`.

- Добавить отдельное состояние ожидания полной первоначальной загрузки и браузерную проверку с работающим Laravel-сервером.

## Сужение scope 07.11.2026 23:30 (планировщик)
- `app/Seller/Runtime/` исключён из scope: правило вопросов и цели живут в `app/Ai/Protected/` и `app/ProtectedOperations/`; напоминания не учитываются. Нужен runtime-файл — пункт в «Нужно от интегратора».


## Остаток 10.11.2026 (планировщик, переоткрыто)
- Остаток 10.11 (планировщик): страница настроек напоминаний (Seller/Settings/Policy/Index) — статистика напоминаний (followupSent/followupReplies) отдаётся через Inertia::defer; пока не пришла — skeleton блока «Статистика» с aria-busy, остальная форма доступна сразу; ошибка загрузки — текст «Не удалось загрузить статистику» и «Повторить».
- Остаток 10.11: Vite-фикстуры E6-03E/O/T/X/Y обновить под отложенный проп без изменения их проверок; PolicySettingsTest — проп отложен (assertInertia loadDeferredProps), права и изоляция бренда статистики; Playwright tests/Browser/E6-03Z/ — skeleton, затем значения, ошибка; свой Vite-сервер в beforeAll/afterAll, axe. Бэкенд правил вопросов и AI-стадии не трогать.

Scope (сужен 10.11, без shared-ai-stages): `app/Seller/Settings/Policy/PolicySettingsController.php`, `resources/js/pages/Seller/Settings/Policy/Index.tsx`, `tests/Feature/Seller/Settings/Policy/PolicySettingsTest.php`, `tests/Browser/E6-03E/fixture.tsx`, `tests/Browser/E6-03O/fixture.tsx`, `tests/Browser/E6-03T/fixture.tsx`, `tests/Browser/E6-03X/fixture.tsx`, `tests/Browser/E6-03Y/fixture.tsx`, `tests/Browser/E6-03Z/`, `docs/results/SP-E6-03Z.md`.
