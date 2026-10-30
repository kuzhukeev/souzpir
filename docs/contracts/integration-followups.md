# Точные изменения планирования после первой контрольной точки

Эти пункты не запускают новые задачи и не меняют действующие claims. Интегратор обновляет queued metadata перед выдачей соответствующей будущей задачи; текущий этап останавливается после БД и первых макетов.

| Когда | Изменение | Причина |
|---|---|---|
| До E0-08 | Выдать точный scope для frontend i18n helper, например `resources/js/lib/i18n/`; добавить dictionary к SharedProps и typed helper | Сейчас E0-08 владеет generated types/shared PHP, но helper path ещё отсутствует в scope. Preview допускает literal RU labels и не ждёт этого изменения |
| До E7-04 и E8-07 | Добавить prerequisite SP-E1-11 для implementations ExportContributor | Интерфейс экспорта принадлежит E1-11; consumer не должен создать его копию или зависеть от ещё несуществующего файла |
| До E8-01 | Уточнить schema deliverables: monitoring_schedules входит в раннюю core schema E8-01 | E8-04 использует расписание до поздних Settings E8-07; E8-07 меняет UI/поведение, не создаёт вторую таблицу |
| До E8-05C | Отдельное bootstrap extension изменение со scope `resources/js/ssr.tsx` и необходимыми shared build/config файлами | В E0-01 текущий scope этого entrypoint не содержит; SSR публичного отчёта нужен позже, не в первой preview |
| До E6-02/E6-08/E4-05 | Проверить integration tests default/disabled runtime ports E3-03B и feature registry bindings | Greetings, catalog prices и warn должны менять runtime через adapters, не вынуждать feature task редактировать чужой Engine |
| До E9-01 | Проверить все реализованные exporter registrations и event subscribers | Отдельные feature tests не доказывают полный межмодульный export и доставку событий |

`registry.json` уже фиксирует monitoring_schedules за E8-01 и ExportContributor за E1-11. Добавление описанных prerequisites не создаёт цикла: E1-11 зависит от E3-04/E8-01, а E7-04/E8-07 находятся ниже этих data layers. Owner scope остаётся обязательным даже если контрактный документ описывает будущий файл.
