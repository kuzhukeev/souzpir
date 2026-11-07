Модель: Claude Sonnet 5

Доработка по расширению scope 08.11 00:30 (планировщик): единый лимит страниц и текст модалки подтверждения V-8.5.
- `app/Vision/Site/Jobs/AuditSiteJob.php:48` — убран хардкод `50`, лимит теперь берётся из `config('features.E8-01B.defaults.crawl_pages')` (единственный источник правды; `SiteInspector` сам ограничивает сверху 50 как раньше).
- `app/Vision/Site/Http/SiteController.php` — проп `pageLimit` из того же конфига передаётся в Inertia-страницу.
- `resources/js/features/Vision/Site/AuditDetails.tsx` (`AuditForm`) — модалка подтверждения показывает по макету `119/e3218d37-V-8.5-confirm.webp`: «Проверка займёт 2–5 минут, проверим до {pageLimit} страниц.» и «Сайт: {url}»; без пропса остаётся прежний общий текст (запасной случай).
- Проп проброшен через `resources/js/pages/Vision/Site/Index.tsx` и тип `Props` (`resources/js/types/Vision/Site/index.ts`).

Проверено:
- `php artisan test tests/Feature/Vision/Site/` — 42 passed, 548 assertions (обновлены ожидания `$limit` в `SiteElapsedE821PTest`, `SiteProgressE821ETest` под `config(...)` вместо хардкода 50).
- `check frontend` (eslint/tsc/vitest/build) — зелёный, 41 файл/250 тестов, включая новый юнит-тест модалки в `audit.test.tsx`.
- Playwright `tests/Browser/E8-21Q/site.spec.ts` — 2 passed (прежний сценарий + новый про текст модалки), скриншот `.local/screenshots/SP-E8-21Q/confirm.png` сверен с макетом `V-8.5-confirm`.
- `check lint` (composer validate, coverage, pint) — зелёный.

## Техдолг
Нет новых находок сверх ранее зафиксированных.

## Принято по умолчанию
- Числовое значение `crawl_pages` (сейчас 5) не менял — задача просила унифицировать источник, а не поднять лимит; видимое число в модалке теперь честно равно реальному лимиту обхода.
