# SP-E4-01AB · Релизы знаний: окно публикации, ссылки входящих, названия полей

**Зачем:** экраны D-4.1/D-4.2, D-4.12 (дефекты SP-E9-01F/SP-E9-01G, журнал docs/sources/02b_mockup_journal.md), отчёт SP-E6-10E «Нужно от интегратора».
**Часть чего:** разбиение очередной SP-E4-01X (UI-часть не зависит от protected-адаптера).
**Зависимости:** нет.

## Приёмка
- D-4.1/D-4.2: две ссылки «Открыть диалог» во входящих (`resources/js/pages/Seller/Knowledge/Releases/Index.tsx`) различимы не только цветом (подчёркивание/иконка) — axe `link-in-text-block` на 1440/1024/390 чистый.
- D-4.12: в окне публикации поясняющая строка об изменениях и отдельная кнопка «Отмена» по макету (`mockups D-4.12`).
- D-4.12: число диалогов, качество по версии, автор и время строк diff — только по однозначной связи (релиз/аудит изменения); без неё «—», автора публикации не приписывать всем изменениям.
- `ReleasesController::runScenarios` передаёт названия опубликованных доп. полей (client_fields опубликованной policy), `Releases/Run.tsx` показывает названия вместо id.
- Тесты: feature (права, изоляция бренда, «—» без связи) в `tests/Feature/Seller/Knowledge/Releases/`; Playwright с axe в `tests/Browser/E4-01AB/`. Только fake, без живых вызовов.

Scope: `app/Seller/Knowledge/Releases/ReleasesController.php`, `app/Seller/Knowledge/Releases/ReleaseComparison.php`, `app/Seller/Knowledge/Releases/IncomingController.php`, `resources/js/pages/Seller/Knowledge/Releases/Index.tsx`, `resources/js/pages/Seller/Knowledge/Releases/Compare.tsx`, `resources/js/pages/Seller/Knowledge/Releases/Run.tsx`, `tests/Feature/Seller/Knowledge/Releases/`, `tests/Browser/E4-01AB/`, `docs/results/SP-E4-01AB.md`.
