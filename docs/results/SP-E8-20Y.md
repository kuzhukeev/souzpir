# SP-E8-20Y · Завершение пересчёта и приоритетов

## Сделано
- V-6.3: проверено и зафиксировано тестом правило «публичный доступ и срок новой версии при нескольких публикациях исходного отчёта, исходные ссылки и сроки сохраняются» (решение владельца 06.11.2026 по SP-E8-20X). `RecalculateMergedReport` не читает и не изменяет `report_publications`.
- V-6.3 (очередь queue.md 07.11): пересчёт объединённого отчёта вынесен из синхронного запроса в `RecalculateMergedReportJob` (ShouldQueue). Контроллер синхронно проверяет только дешёвое условие (есть подтверждённый отчёт — иначе откат алиасов и ошибка `source`, как раньше), затем кладёт в кэш `vision:competitors:merge:<org>:<brand>` стадию `queued` и диспатчит job; job переводит стадию в `recalculating`→`complete`/`failed`. Экраны `/vision/competitors` и `/vision/competitors/merge` получают проп `mergeProgress` и показывают баннер «Пересчитываем, 1–2 минуты» (как на макете V-6.3 recalculating) со спиннером, поллинг `router.reload` каждые 2с, пока стадия `queued`/`recalculating`; при `failed` — отдельное сообщение об ошибке.
- V-5.7: без изменений, остаётся заблокирован решением владельца (вопрос Q2 от 07.11, `docs/decisions-pending.md`); код по-прежнему не придумывает приоритет/шаги.

## Проверки
- `php artisan test tests/Feature/Vision/Competitors/`: 21 тест, 509 проверок (новый `test_merge_shows_recalculating_banner_until_the_queued_job_completes_or_fails` — Queue::fake, проверены стадии queued→complete и queued→failed через `mergeProgress`; остальные тесты модуля — без регрессий).
- `./vendor/bin/pint --test app/Vision/Competitors tests/Feature/Vision/Competitors` — ок.
- `npx playwright test tests/Browser/E8-20X tests/Browser/E8-20W tests/Browser/E8-20G tests/Browser/E8-20F` — 8 сценариев, без регрессий.
- `check lint`, `check frontend` (eslint/tsc/vite build) — ок.
- Сверено с макетом `110/1f71e876-V-6.3-recalculating.webp`.

## Остаток
- V-5.7: источник сопоставления с конкурентами, формула и пороги приоритета, источник шагов рекомендаций — решение владельца не принято.

## Техдолг
- В тестовом/dev-окружении `QUEUE_CONNECTION=sync`, поэтому job выполняется синхронно в том же запросе и баннер «Пересчитываем» практически не виден человеку (виден только при реальном асинхронном воркере) — поведение корректно, но визуально не проверяемо в этом окружении.

## Принято по умолчанию
- Для V-6.3 правило при нескольких публикациях распространено на уже работающий механизм (ничего не трогать) без автосоздания новой публикации для пересчитанной версии.
- Баннер и поллинг реализованы по образцу существующего `AuditSiteJob`/`AuditProgress` (кэш стадии по ключу org+brand, `router.reload` раз в 2с) для единообразия с остальным кабинетом.
