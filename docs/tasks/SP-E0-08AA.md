# SP-E0-08AA · Browser CI: оставшиеся подтверждённые регрессии

Часть SP-E0-08Z: после принятия E4-06E устранить остальные подтверждённые падения run 42348786968, предварительно сверив каждое с актуальным main. Не ослаблять проверки; внешние вызовы только fake.

Первый независимый этап: E4-06H/preview — сохранённый документ и повторный просмотр. Проверить browser и изменённое поведение. Изменение других файлов только после уточнения точного scope интегратором.

Остаток для последующих этапов: шард 1 — E1-01/invitation-safety (2), E6-08D/catalog; шард 2 — E4-06L/preview (2), E4-08/fewshot-more, E4-08D/fewshot (2), E4-10C/fewshot, E4-10E/sandbox, E5-01G/channels, E5-01I/channels, E5-07B/preview, E5-07C/preview; шард 3 — E6-09D/crm, E7-04/details, E7-04D/analytics, E7-04N/subscription, E8-05/report, E8-14/quiz, E8-14B/facts; шард 4 — E8-16I/measurements, E8-16U/header, E8-19/report, E8-19C/D/E/public, E8-21D/E/F/G/M/N/site. Учитывать ownership.

Scope первого этапа: tests/Browser/E4-06H/preview.spec.ts, tests/Browser/E4-06H/fixture.tsx, tests/Browser/E4-06H/fixture.html, tests/Browser/E4-06H/vite.config.ts, resources/js/pages/Seller/Knowledge/Imports/Index.tsx, app/Seller/Knowledge/Imports/ImportsController.php, routes/features/E4-06.php, docs/results/SP-E0-08AA.md.
