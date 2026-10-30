# SP-C-01 — результат

Дата: 31.10.2026. Ветка `codex/sp-c-01`, worktree `C:/OSPanel/home/souzpir.local/.worktrees/sp-c-01`.

Подготовлены конкретные v1 контракты в `docs/contracts/`: loader specification, tenant/shared/AI/runtime DTO, HTTP errors 403/404/409/422, idempotency/claim/finalize/delivery, immutable knowledge releases и extension ports для greetings/catalog/Guard severity/exporters. Машинный реестр закрепляет 94 таблицы (включая framework storage), 16 contracts, 18 событий и 27 shared областей за единственными координаторами.

Уточнение по реальному донору: `Knowledge::all(string $file)` требует имя YAML namespace. Эквивалентность описана по каждому из десяти `Knowledge::FILES`; `toArray()` — новый отдельный snapshot метод. Ai bridge сохраняет nullable content, toolCalls, исходный purpose и отдельный budget family.

Учтён текущий checkpoint пользователя: только БД и первые макеты, затем показ и ожидание команды. E0-01/P-01 могут работать без будущих tenant/auth/shared aliases через изолированные local/testing preview props; production authorization не заменяется no-op. Preview допускает literal RU labels. SSR и полная i18n-интеграция не блокируют первый просмотр; точные будущие scope/dependency изменения записаны в integration-followups.md.

Проверки:

- `python docs/contracts/validate.py` — успешно: 94 таблицы, 16 contracts, 18 событий, 27 shared areas, 12 доменов; владельцы определены в planning/tasks.json, declared paths входят в scope, FK parents определены, domain import cycles = 0; notification vocabulary = 6 Vision + 7 Seller. Сетевых вызовов нет.
- `git diff --check` — успешно.
- Ручная сверка source LlmClient/LlmResult/AiCallContext/Knowledge и исходных V-11.4/D-11.4 — сигнатуры и словари отражены без подмены донорского поведения.
- Все изменения находятся в разрешённых `docs/contracts/` и `docs/results/SP-C-01.md`.

Ограничения: это документационный contract baseline, не готовые PHP implementations. Миграции, HTTP и runtime тесты выполнят их задачи-владельцы; schema validator не доказывает работу приложения. Приложение, БД и интеграции в этой задаче не запускались. Screenshots не применимы: UI не изменялся. Исходные архивы и основной checkout не изменены.
