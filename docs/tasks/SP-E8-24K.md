# SP-E8-24K · Доступ к отчёту: визуальные состояния

**Зачем:** завершение экранов по решению владельца и журналу макетов.
**Часть чего:** остаток SP-E8-24J, docs/results/SP-E8-24J.md.
**Зависимости:** SP-E8-24J.

## Приёмка
- V-11.5: сверить пустое и ошибочное состояния, внешние адреса и все темы с макетом, сохраняя действующие права.
- Проверить основной сценарий, права, изоляцию брендов, ошибки и макет; живые внешние вызовы отключены. Остаток оформить по AGENTS.md.

Scope: `resources/js/pages/Vision/Settings/`, `tests/Browser/E8-24K/`, `docs/results/SP-E8-24K.md`.

## Остаток 07.11.2026 (аудит экранов, интегратор)
- V-11.5: по макету у наблюдателя нет доступа к замерам: `config/permissions.php` (строка наблюдателя) и колонка доступа в `resources/js/pages/Vision/Settings/Index.tsx`; feature-тест прав наблюдателя на маршруты замеров.
- Дополнительный scope: `config/permissions.php`, `app/Vision/Settings/`, `tests/Feature/Vision/Settings/`.


## Уточнение 08.11.2026 00:55 (планировщик)
- По возврату исполнителя добавлены в scope `app/Vision/Measurements/AnswersController.php`, `app/Vision/Measurements/MeasurementsController.php`, `tests/Feature/Vision/Measurements/`: проверка прав наблюдателя (full_access) на маршрутах ответов/замеров V-3.4/V-11.5. Другие файлы замеров не менять.
