# SP-F-01 — темы и токены

Выполнено 31.10.2026 в `codex/sp-f-01`; база включает merged SP-E0-01 `f2fa3e2942317596142d98ff57b427f145d4d75c`.

## Результат

- Три темы `confectionery`, `pyro`, `neutral`, общие semantic action/status/ink/icon roles и отдельные брендовые акценты. Цвета уточнены по PNG1/PNG13; action/nav сохранены тёмно-зелёными. Согласованные имена и Tailwind 4 consumer recipe — `docs/design/tokens.md`.
- Manrope variable 200–800 Cyrillic/Latin WOFF2 без изменения байтов; SHA-256 совпадают с донором. Официальный полный OFL и provenance находятся в `public/fonts/`.
- CSS подключается существующим Vite eager glob. `@tailwind utilities source(none)` включает обработку standalone theme без второго reset/source scan; `@theme static` выпускает все tokens. Наивный вариант только с `@theme static` был отвергнут тестом реальной сборки и исправлен.
- Shared app.css, manifests/locks, feature PHP/React и чужие scopes не изменены.

## Проверки

| Проверка | Результат |
|---|---|
| `npm test -- tests/Unit/ThemeTokens` | 9 tests PASS; фактические CSS values, 90 активных текстовых пар ≥4.5:1 и 36 icon/focus/control пар ≥3:1 для трёх тем; font hashes/license; реальные emitted vars и Tailwind arbitrary-variable utilities |
| `npm run build` | PASS; обычные entrypoints, 2303 transformed modules; отдельный CSS темы 2.25 kB, все declarations присутствуют |
| `npm run typecheck` | PASS |
| `npx --no-install eslint tests/Unit/ThemeTokens` | PASS |
| Prettier по собственным CSS/TS/Markdown | PASS |

Vite сообщает, что `/fonts/*.woff2` разрешатся в runtime: Laravel plugin не копирует public assets. Файлы уже находятся в `public/fonts/`, объявленные URL и WOFF2-сигнатуры проверены тестом. Внешних font requests нет.

## Границы проверки

Это инфраструктурная задача без собственных экранов. Оригинальные пять PNG просмотрены, ровные цветовые патчи измерены; скриншоты готового UI относятся к SP-P-01 после merge тем. Здесь не утверждается завершённая визуальная сверка React-экранов, browser a11y или измерение отрисованного текста. Изменение opacity/background в consumer требует проверки его конечного контраста. Декоративный border и disabled controls не являются активными текстовыми ролями.

Установка npm выполнена из существующего lock-файла в своём worktree. Реальные БД, LLM, mail, Telegram, scheduler и рабочие каналы не использовались.
