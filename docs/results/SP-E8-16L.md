# SP-E8-16L — остаток 07.11 (V-4.4: пересчёт отчёта на шаге «Готово»)

- Остаток 07.11.2026: на шаге «Готово» мастера уточнений теперь показывается «Спасибо. Отчёт пересчитан с учётом ваших уточнений» и блок «Что изменилось»: «Видимость: X % → Y %» — проверено: `mockups V-4.4` (92d54d74-V-4.4-success.webp), Playwright `tests/Browser/E8-16L/wizard-done.spec.ts`, скриншот `.local/screenshots/SP-E8-16L/wizard-done.png`.
- Сохранённый снимок отчёта пересчитывается по образцу `RecalculateMergedReport` (V-6.3): новый класс `App\Vision\Reports\RecalculateClarifiedReport` читает последнюю ревизию, применяет `ClarificationVisibility`, если есть применённые решения — вставляет новую ревизию `vision_reports` (исходная не меняется) — проверено: `ClarificationWizardTest::test_done_step_recalculates_and_persists_new_revision_without_mutating_source`.
- Запись новой ревизии доступна только участнику с правом `vision.measure.run` (viewer не инициирует пересчёт на обычном просмотре) — проверено: `ClarificationWizardTest::test_done_step_does_not_recalculate_for_viewer_without_write_right`.
- Если применённых уточнений нет (шаг «Готово» без решений, меняющих видимость), показывается прежний текст «Ответы сохранены».
- Тесты: PHPUnit `ClarificationWizardTest` (7, в т.ч. 2 новых), весь `tests/Feature/Vision/Reports` и `tests/Feature/Vision/Clarifications` (42 теста) — зелёные; Playwright `tests/Browser/E8-16L` (2 спеки) — зелёные; TypeScript, ESLint, Pint — без ошибок.

## Остаток
Нет — пункт «Остаток 07.11.2026 (аудит экранов, интегратор)» карточки закрыт полностью.

## Техдолг
- Нет новых рисков.

## Принято по умолчанию
- Подпись под блоком изменений — «После уточнения упоминаний» (по макету дан частный случай «После объединения названий»; «уточнения упоминаний» точнее описывает общий случай, так как пересчёт зависит от решений по упоминаниям на любом шаге).

## Нужно от интегратора
Нет.
