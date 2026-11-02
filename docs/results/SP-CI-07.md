# SP-CI-07

## Сделано
- Ночному `race` job назначен `DB_USERNAME=souzpir_parallel`.
- Перед Composer добавлена точная копия проверенной подготовки роли и четырёх worker БД из `php-tests`; concurrency main не менялась.

## Проверка
- `git diff --check`: зелёный.
- Ручной [workflow_dispatch 41412123385](https://github.com/kuzhukeev/souzpir/actions/runs/41412123385): подготовка PostgreSQL зелёная; тесты стартуют в БД, принадлежащей `souzpir_parallel`; 158 прошло, 11 упало, 3 пропущено. Оставшиеся падения — SP-CI-08 по решению владельца.
- Обычный CI ветки на `274b700a`: зелёный [run 41412057470](https://github.com/kuzhukeev/souzpir/actions/runs/41412057470).

## Техдолг
- Нет.

## Принято по умолчанию
- Нет.
