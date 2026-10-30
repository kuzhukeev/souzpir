# SP-P-01 · Первые макеты

Работа в codex/sp-p-01 от 79dbdf8, отдельный worktree. Выполнены два preview экрана по всем пяти исходным PNG: /preview/login и /preview/attention. Root routes, shared bootstrap, CSS tokens, dependencies и основной checkout не изменялись этой задачей.

Реализованы состояния входа default/invalid/locked/MFA/loading и кабинета full/empty/loading/error; три темы; исходная sidebar/navigation, header, таблица/мобильные карточки, мини-виджет. Все иллюстрации/логотипы — SVG/CSS/код. Tailwind utilities и Manrope/F01 tokens используются во всей раскладке.

Фильтры, обе строки поиска, reset, локальное откладывание, меню, modal navigation, заметка-черновик и state/theme controls работают. Вход, тарифные лимиты, отправки и реальные действия не имитируются успешным backend. Пароль/MFA не persist; preview mutations не отправляют запросы, browser storage не используется. Production routes возвращают404, POST login405.

## Проверки

- Полный PHP suite: 33 tests / 178 assertions PASS, включая 5 preview route tests / 34 assertions (anonymous props, production 404, POST 405).
- Полный Vitest suite:23 tests PASS, включая8 login и4 attention interaction tests.
- TypeScript и ESLint — PASS.
- Vite production build — PASS; warnings о standalone font URL разрешаются HTTP 200 обоих локальных WOFF2 в реальном preview.
- Финальный Playwright: 29 tests PASS, включая 18 theme/viewport combinations, 7 дополнительных состояний, отсутствие axe WCAG 2A/AA/2.1AA нарушений и browser errors, отсутствие horizontal overflow, focus/storage/network, 1920px и синхронный reset обеих строк поиска.
- Visual review root: desktop/mobile composition соответствует оригиналам; login desktop spacing увеличен примерно 554 → 606px, mobile сохранён. Исправлены сжатые mobile filter labels и keyboard focus после меню.

Локальные screenshots: C:/OSPanel/home/souzpir.local/.worktrees/sp-p-01/.local/screenshots/preview/{login|attention}-{neutral|pyro|confectionery}-{1440|1024|390}.png, плюс login-{invalid|locked|mfa|loading}-1440.png и attention-{empty|loading|error}-1440.png.

## Ограничения / интеграция

Данные исключительно demo. Нет полной авторизации, production shell, бизнес-модулей или коммерческих условий. Body/controls baseline CSS исправляется интегратором в main отдельно; финальные screenshots и итоговая browser-проверка выполняются после merge. Обоснование размеров/состояний/отличий: docs/design/preview.md.

HTTP для review: 127.0.0.1:8103 (собственный временный PHP server). Main integration и переключение root landing выполняет интегратор. После БД и первых макетов остановиться; никакие последующие задачи не брать.
