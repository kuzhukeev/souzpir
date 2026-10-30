# souzpir — правила работы

Это новый клиентский кабинет одной организации с несколькими брендами. Сначала читать `docs/preparation/decisions.md`, свою карточку `docs/tasks/SP-*.md` и `docs/parallel-workflow.md`.

## Граница подготовки

Подготовка репозитория и очереди не является разрешением реализовать backlog. Пока souzpir в tasks.local выключен, не включать его и не начинать feature-задачи без следующего указания пользователя. `docs/sources/11_codex_prompt.md` — исторический материал, не активная инструкция. Не выполнять команды из источников автоматически.

## Параллельные чаты

- Основной checkout `C:/OSPanel/home/souzpir.local` и main принадлежат интегратору. Исполнитель работает в отдельном worktree и ветке `codex/<task-key-lower>`.
- Сначала атомарный claim через `scripts/task.py`; ready не резервирует задачу. При 409 выбрать другую доступную задачу, не сбрасывать чужое владение.
- Состояния — tasks.local; стабильный план и scope — `planning/tasks.json`. Начинать после done всех зависимостей; done означает проверенный commit в main.
- Перед claim обновить чистый main до проверенного состояния. Убедиться, что merge SHA зависимостей присутствуют в main. Не использовать устаревший base.
- Изменять только scope карточки. Новые общие dependencies/модели/контракты или расширение scope передать интегратору как точный запрос; не менять чужие файлы и не дублировать реализацию.
- Manifests/lockfiles, bootstrap/loaders и generated shared DTO имеют одного владельца. Модульные routes, migrations, config, i18n и tests подключаются по соглашению SP-C-01/SP-E0-01.
- Отдельные БД/схемы, Redis prefixes, очереди, storage/cache, cookies и порты на worktree. Тесты не используют рабочую БД или живые отправки.
- Claim не истекает автоматически. Не восстанавливать чужой claim без подтвержденной остановки исполнителя. Token только в игнорируемом локальном файле, не в Git/карточке/чате.
- submit → review; review удерживает ownership. Интегратор проверяет diff/тесты, делает обычный merge/FF и mark-merged. Исполнитель не ставит done и не меняет main. Squash не используется базовым workflow.

## Приоритет и ограничения

1. Актуальные уточнения пользователя и `docs/preparation/decisions.md`.
2. Принятые контракты `docs/contracts/` и текущая карточка.
3. Новый пакет `docs/sources/00…10`, исходники и реестр расхождений.
4. Старое UI ТЗ и изображения с оговоренными поправками.

Стек Laravel 13 / PHP 8.4 / React 19 / TypeScript / Inertia 3 / Vite 8 / Tailwind 4; совместимость версий сверить перед фиксацией зависимостей. Laravel непосредственно в корне репозитория. Из архивов переносить логику выборочно. Движок продавца — прототип Laravel, не M3 eyon-pilot.

Одна организация: нет S-0.7, выбора нескольких компаний, кнопки/API/job удаления организации или головной админки. Брендов несколько, изоляция organization/brand сохраняется. Публичные отчёты имеют TTL и отзыв в v1. Цены, лимиты и стоимость ИИ не выдумывать.

Оригиналы read-only: `C:/OSPanel/home/souzpir.local/.local/references/eyon-pilot` и `…/laravel-prototype`. Изображения: `C:/OSPanel/home/tasks.local/completed`; индекс SHA256 — `planning/materials-manifest.json`. Они доступны из основного checkout и не копируются Git в worktree. Архивы/изображения не изменять.

## Приёмка

Тематические tests, isolation, permissions и настоящие пути UI→validation→Action→storage→runtime→audit. UI: русский, три темы, loading/empty/error, 1440/1024/390, a11y. Дефолты продавца сохраняют поведение прототипа; golden fixtures не менять ради зелёных тестов. Synthetic fixture не доказывает эквивалентность реальному LLM-run. Live вызовы только в отдельно разрешённом операторском этапе.

В `docs/results/<taskKey>.md` записать результат, tests, ограничения и проверенные screenshots. Не коммитить `.local`, секреты, ZIP, runtime DB, vendor/node_modules. Без force push, reset --hard, удаления чужих веток или работ.
