# SP-E8-04C · Подтверждение снимка

**Зачем:** V-4.1–V-4.4 из `docs/sources/02_screens_matrix.md`: пользователь подтверждает результат замера и видит шаги сохранения снимка.

**Часть чего:** последнее продолжение SP-E8-04 после SP-E8-04B. После него SP-E8-05 получает подтверждённый снимок для внутреннего отчёта; расписание V-11 остаётся в SP-E8-07.

За 1–3 часа добавить принадлежащие модулю таблицы `snapshot_confirmations` и `answer_facts`, защищённое действие подтверждения и минимальный StepProgress. Использовать только сохранённые ответы текущего бренда; повторное подтверждение идемпотентно. Без live LLM и без изменений соседних модулей. Зависимость: SP-E8-04B.

Scope: `app/Vision/Measurements/`, `database/migrations/E8-04C/`, `resources/js/pages/Vision/Measurements/`, `resources/js/features/Vision/Measurements/`, `routes/features/E8-04.php`, `tests/Feature/Vision/Measurements/`, `tests/Browser/E8-04/`, `docs/results/SP-E8-04C.md`. Приёмка: подтверждение только завершённого замера своего бренда, повтор безопасен, неподтверждённый снимок не выглядит готовым, видны loading/error/empty. Отчёт и submit по AGENTS.md.
