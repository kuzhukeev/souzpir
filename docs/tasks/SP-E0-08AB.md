# SP-E0-08AB · Browser CI: оставшиеся регрессии

Остаток принятой SP-E0-08AA. Сверить падения run 42348786968 с текущим main, не ослаблять тесты. Первый этап: E4-08/fewshot-more; проверить причину и исправить существующие тесты или поведение, фикстура должна запускаться на свободном порту. Без живых вызовов.

Остаток: шард 1 — E1-01/invitation-safety (2), E6-08D/catalog; шард 2 — E4-08/fewshot-more, E4-08D/fewshot (2), E4-10C/fewshot, E4-10E/sandbox, E5-01G/channels, E5-01I/channels, E5-07B/preview, E5-07C/preview; шард 3 — E6-09D/crm, E7-04/details, E7-04D/analytics, E7-04N/subscription, E8-05/report, E8-14/quiz, E8-14B/facts; шард 4 — E8-16I/measurements, E8-16U/header, E8-19/report, E8-19C/D/E/public, E8-21D/E/F/G/M/N/site. Учитывать ownership.

Scope первого этапа: `tests/Browser/E4-08/fewshot-more.spec.ts`, `tests/Browser/E4-08/fixture.tsx`, `tests/Browser/E4-08/fixture.html`, `tests/Browser/E4-08/vite.config.ts`, `resources/js/pages/Seller/Knowledge/Fewshot/Index.tsx`, `resources/js/pages/Seller/Knowledge/Fewshot/SampleCard.tsx`, `resources/js/pages/Seller/Knowledge/Fewshot/BadAndDialog.tsx`, `docs/results/SP-E0-08AB.md`.
