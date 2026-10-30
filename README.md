# Союз Пир — подготовка клиентского кабинета

Репозиторий: https://github.com/kuzhukeev/souzpir (private).
Каталог: `C:/OSPanel/home/souzpir.local`.
Очередь: http://tasks.local/ — проект souzpir, разработка разрешена 31.10.2026.

Приложение ещё не реализовано. Подготовлены требования, аудит архивов и изображений, очередь для отдельных чатов, владение задачами и Git worktree.

## С чего читать

- [Решения](docs/preparation/decisions.md): одна организация, несколько брендов; Laravel+React/Inertia; без удаления организации/головной админки; TTL и отзыв публичных ссылок v1.
- [Очередь 80 задач](planning/QUEUE.md) и [машинный план](planning/tasks.json).
- [Правила исполнителя](AGENTS.md) и [worktree/очередь](docs/parallel-workflow.md).
- Аудит [требований](docs/preparation/spec-audit.md), [исходников](docs/preparation/source-audit.md), [макетов](docs/preparation/visual-audit.md), [очереди](docs/preparation/queue-audit.md).
- [Исходный пакет](docs/sources/01_project_overview.md), [покрытие экранов](planning/screen-coverage.json), [индекс материалов](planning/materials-manifest.json).

## Выполнение очереди

Пользователь разрешил старт 31.10.2026; интегратор включает souzpir в tasks.local. Первые независимые задачи: SP-C-01 (контракты) и SP-T-01 (offline baseline). После контрактов — SP-E0-01 (скелет). Остальные выдаются по готовности зависимостей и отсутствию пересечения scope. Чаты самостоятельно не создаются.

Один чат берёт одну карточку через `scripts/task.py claim`, пишет код в собственном worktree и отправляет в review. Интегратор проверяет и сливает в main; после проверки SHA задача становится done. Команды — в workflow.

Оригинальные ZIP остаются в Downloads; распакованные доноры — в `.local/references/`. В Git они не загружены. Изображения — в `C:/OSPanel/home/tasks.local/completed`. Для другого компьютера нужны эти материалы; пути и контрольные суммы есть в manifest.

## Проверки подготовки

Python 3.11+, stdlib для coordinator/tests. `inventory_materials.py` использует Pillow только для контактных листов.

```text
python scripts/build_plan.py
python -m unittest discover -s tests/preparation -v
python scripts/task.py --help
python scripts/task.py ready
```

`tools/taskdesk/` содержит версионируемый патч существующего tasks.local и его тесты. Это не код будущего Laravel-приложения. Тесты приложений-доноров не запускались; это отдельные задачи следующей итерации.
