# SP-CI-07

## Сделано
- Ночному `race` job назначен `DB_USERNAME=souzpir_parallel`.
- Перед Composer добавлена точная копия проверенной подготовки роли и четырёх worker БД из `php-tests`; concurrency main не менялась.

## Проверка
- `git diff --check`: зелёный.
- `workflow_dispatch`: результат будет указан после ручного прогона.

## Техдолг
- Нет.

## Принято по умолчанию
- Нет.
