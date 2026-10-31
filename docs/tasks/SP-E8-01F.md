# SP-E8-01F · Анкета и версии профиля Видения

Прочитать AGENTS.md и решения проекта. Создать tenant-scoped схему анкеты, неизменяемые версии профиля и контракт кандидатов вопросов; безопасно подключить отправленный профиль к замороженному профилю Видения.
Зависимости: SP-E8-01, SP-E8-01D, SP-E8-01E.
Область записи: app/Vision/Core/Profiles/, app/Vision/KnowledgeBridge/, database/migrations/E8-01F/, docs/modules/E8-01F.md, docs/results/SP-E8-01F.md, tests/Feature/Vision/Core/Profiles/, tests/Unit/Vision/Core/Profiles/.
Проверки: Составные tenant FK, один draft на бренд, конкурирующие сохранения и immutable версии; audit и изоляция. Runtime сохраняет version-bound snapshot, не ослабляет grant и не выдаёт слова клиента за подтверждённые факты. Существующие измерения и действующие grants не теряются; нет live AI. Работа в отдельном worktree, fake AI, после review интегратор выполняет merge.
