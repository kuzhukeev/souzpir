# Работа с очередью и worktree

Команды выполняются из основного checkout с помощью Python и `scripts/task.py`. Проект должен быть включён, зависимости задачи — в `done`, а их merge-коммиты — в истории чистого `main`. `ready` показывает задачи, для которых нет локальных claim, worktree и ветки, а в `blockedLocal` перечисляет оставшиеся локальные препятствия; это подсказка, а не резервирование задачи.

```powershell
$taskPython = 'C:\Users\eralash\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
$taskCli = 'C:\OSPanel\home\souzpir.local\scripts\task.py'
& $taskPython $taskCli ready
& $taskPython $taskCli claim SP-XXX --worker 'codex:<chat-id>'
```

`claim` атомарно получает владение и создаёт ветку и отдельный worktree от проверенного `main`. При конфликте владения выбирайте следующую задачу. Работайте в указанном worktree и scope карточки; token остаётся только в `.local/claims/`. Окружение каждого worktree изолировано, как описано в `AGENTS.md`.

```powershell
& $taskPython $taskCli submit SP-XXX --summary 'Что сделано' --check 'Выполненная проверка'
& $taskPython $taskCli resume SP-XXX --reason 'Замечание review'
& $taskPython $taskCli mark-merged SP-XXX
```

`submit` проверяет чистоту worktree, ветку, базу и scope и передаёт результат на review. `resume` возвращает задачу владельцу для исправления. Интегратор проверяет результат, выполняет обычное слияние в `main` и только затем запускает `mark-merged`: команда проверяет, что коммит результата входит в историю `main`, и отмечает задачу done. Подробности и другие команды: `task.py --help` и справка конкретной команды. Не сбрасывайте чужое владение и не удаляйте чужую работу.
