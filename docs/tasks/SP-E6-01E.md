

## Остаток 07.11.2026 23:20 (после приёмки)
- Подключить остальные служебные фразы к текстам клиенту: `reminder` и `goodbye` — в `app/Seller/Followups/` (PrepareFollowups/FollowupSender: при сохранённой фразе она заменяет текст напоминания/прощания); `error` — текст клиенту при сбое хода (`unavailable`) там, где ход завершается без текста; `out_of_hours` уже идёт через `BrandRuntimeSchedule::fallbackPhrase`, `opt_out` на первом ходе не трогать (SP-E6-03V). Только явно сохранённая фраза, без дефолтов; fake-тесты, бренд.
- Дополнительный scope: `app/Seller/Followups/`, `tests/Feature/Seller/Followups/`.
