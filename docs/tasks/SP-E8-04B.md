# SP-E8-04B · Ход и ответы замера

**Зачем:** V-3.3–V-3.5 из `docs/sources/02_screens_matrix.md`: после запуска пользователь должен видеть ход слотов, ответы и повтор, а не только ожидающий план.

**Часть чего:** продолжение SP-E8-04. В `main` уже есть список и запуск. После этой карточки останется подтверждение снимка V-4 в SP-E8-04C; расписание V-11 относится к SP-E8-07.

За 1–3 часа подключить существующий `MeasurementRunner` к защищённому fake-only ходу, показать честные состояния слотов и сохранённые ответы/деталь текущего бренда; повторить замер из того же утверждённого набора. Не включать live LLM и не менять `app/Vision/Core/` или чужие модули. Зависимость: SP-E8-04.

Scope: `app/Vision/Measurements/`, `resources/js/pages/Vision/Measurements/`, `resources/js/features/Vision/Measurements/`, `routes/features/E8-04.php`, `tests/Feature/Vision/Measurements/`, `tests/Browser/E8-04/`, `docs/results/SP-E8-04B.md`. Приёмка: fake-слот выполняется и сохраняется, чужой бренд недоступен, pending/failed видны честно, повтор создаёт отдельный план. Отчёт и submit по AGENTS.md.
