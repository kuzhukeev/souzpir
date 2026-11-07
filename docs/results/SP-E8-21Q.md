Модель: Claude Sonnet 5

Остаток 07.11 (аудит экранов) по V-8.5 закрыт в пределах scope задачи:
- Этапы проверки переименованы по макету `119/1837ac62-V-8.5-progress.webp`: «Доступ ИИ-ботов → Страницы сайта → Структурированные данные → Итоговая оценка» (технические `queued/inspecting/analyzing/saving` сопоставлены 1:1 с этими подписями в списке этапов и в прогресс-баре).
- `remaining_seconds` теперь считается в `AuditSiteJob` как среднее время на уже проверенную страницу × оставшиеся страницы; «Нет данных» показывается только пока не проверена ни одна страница, дальше — «Осталось около N мин».

Проверки:
- `php artisan test tests/Feature/Vision/Site/` — 42 теста, 548 проверок (обновлены ожидания `remaining_seconds` в SiteElapsedE821PTest, SiteProgressE821ETest, SiteTimingE821QTest под новый расчёт — проверено.
- Playwright `tests/Browser/E8-21Q/site.spec.ts`, Chromium 1440 — проверено (подписи этапов и «Осталось около 1 мин.»).
- `check lint` (composer validate, coverage, pint) — проверено, зелёный.
- `check frontend` (eslint, typecheck, vitest, build) — зелёный; один несвязанный тест `tests/Feature/Preview/attention.test.tsx` падает по таймауту jsdom/canvas и проходит при повторном изолированном запуске — не трогал, вне scope.
- Скриншот: `.local/screenshots/SP-E8-21Q/completed.png`, 1440, светлая тема.

## Остаток
- «В модалке подтверждения — проверим до 50 страниц (лимит из config)» не сделано: модалка в `resources/js/features/Vision/Site/AuditDetails.tsx:202-207` вне scope карточки (`resources/js/pages/Vision/Site/` не включает `features/`), а реальный лимit 50 в `AuditSiteJob.php:48` сейчас захардкожен и не совпадает с `config('features.E8-01B.defaults.crawl_pages')` (=5, файл `config/features/E8-01B.php` тоже вне scope). См. «Нужно от интегратора».

## Техдолг
- Нет новых находок сверх указанного в «Остатке».

## Принято по умолчанию
- Оценка оставшегося времени = `ceil((прошло_с / обработано) × (всего − обработано))`, секунды округляются вверх до минут в UI («Осталось около N мин»), минимум 1 мин при ненулевом остатке.

## Нужно от интегратора
- `config/features/E8-01B.php`: поднять `crawl_pages` с 5 до 50 (или завести отдельный ключ), чтобы лимит страниц был единым источником правды для `AuditSiteJob.php:48` и для текста модалки.
- `resources/js/features/Vision/Site/AuditDetails.tsx:204-207`: заменить текст модалки на «Проверка займёт 2–5 минут, проверим до {limit} страниц» с лимитом из пропса (прокинуть из `SiteController::index`).
- После этой правки поправить два чужих Playwright-теста, которые сейчас проверяют старые подписи этапов: `tests/Browser/E8-21M/site.spec.ts:11` и `tests/Browser/E8-21N/site.spec.ts:12` — `'Анализ данных · выполняется'` → `'Структурированные данные · выполняется'`.
