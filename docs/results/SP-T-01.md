# SP-T-01 — offline baseline прототипа

Выполнено в ветке `codex/sp-t-01`, отдельном worktree. Граница записи сохранена: `docs/baseline/`, `tools/baseline/`, `tests/fixtures/prototype/` и этот результат. Исходный read-only прототип не менялся; зависимости установлены только в игнорируемой `.local/prototype`.

Результат:

- Manifest всех 29 исходных persona IDs и 12 явно перечисленных manual IDs.
- 12 synthetic manual traces: реальная Policy и scheduler донора на фиксированных входах/SQLite памяти.
- 20 реально отрендеренных prompt snapshots: 4 шаблона × 5 состояний, с SHA-256 и безопасной нормализацией.
- Recording/replay adapter для исходного LlmClient, 3 synthetic LLM fixtures и совпавший replay двухходового Orchestrator-сценария.
- Fail-closed обработка missing/mismatch/unconsumed fixtures, запрет перезаписи evidence/live режима; секреты редактируются до сохранения.
- Fingerprints исходных app/config/knowledge/prompts/tests/tools/composer.lock и воспроизводимые команды.

Проверки:

- Исходный PHPUnit на PHP 8.4.19: **31 passed, 71 assertions**, без изменения ожиданий. Первый запуск выявил отсутствующие runtime cache directories; после их создания suite зелёный.
- Harness selftest: **14 checks passed**, включая negative checks mismatch, missing, неизвестный provenance, unconsumed fixtures, overwrite и live mode.
- `verify.py`: **41 cases, 20 snapshots, 12 synthetic, 29 missing, 0 real recorded**; проверены branch policies, scheduler boundaries, SHA оригинального донора.
- Повторная генерация совпала с committed fixtures через `--compare`; утверждённый набор не перезаписывается автоматически.
- `--require-recorded` ожидаемо возвращает ошибку `0/41`: synthetic fixtures не засчитываются как реальные записи.

Ограничение: **полная эквивалентность реальному LLM не заявляется**. Все 29 persona conversations ожидают отдельно разрешённого live capture SP-T-03; manual traces не проверяют extraction/формулировки реальной модели. Для checkpoint БД/первых макетов этот блокер не нужен. Реальные LLM/mail/Telegram/webhook вызовы не выполнялись. UI отсутствует в scope, screenshots неприменимы.

Инструкция: `docs/baseline/README.md`. Сводка исходных тестов: `docs/baseline/prototype-tests.json`. Исполнитель сдаёт задачу в review; main/merge не изменяет.
