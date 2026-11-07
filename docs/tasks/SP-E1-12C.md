# SP-E1-12C · Оболочка: дефекты входа, профиля и интеграций

**Зачем:** решение владельца от 03.11.2026 по повторному аудиту 160 экранов закрыть оставшиеся расхождения с макетами; экраны S-0.1, S-0.4, S-5.1, S-7.1, S-7.3.
**Часть чего:** дефекты из повторного аудита 160 экранов: сданный код работает не так, как обещает интерфейс.
**Зависимости:** SP-E1-01H, SP-E1-08D, SP-E1-10G, SP-E1-08C (дождаться merge).

- Профиль: страницы S-7.x отдают 404, пока выключен флаг профиля (app/Shell/Profile/RequireProfileSettings.php:14, config/features/E1-08.php). Если флаг можно включить по ТЗ, он включается и это проверяет тест; если для этого нужно решение владельца, вопрос записывается в docs/decisions-pending.md.
- Интеграции S-5.1: плитка «Виджет» ссылается на несуществующее имя маршрута 'seller.widget.index' (app/Shell/Integrations/IntegrationsController.php:34); connected_at всегда null (:42, :48, :50). Ссылка ведёт на действующую страницу виджета, дата «Подключено с» берётся из данных канала; тест на обе плитки.
- Смена почты S-7.3 не работает в production (app/Shell/Profile/EmailChanges.php:31): письмо подтверждения уходит только в fake-режиме. В production используется настроенный почтовый транспорт, в CI только fake.
- Восстановление пароля S-0.4: после смены пароля flash «Пароль изменён» не выводится на странице входа (PasswordController.php:146, :191; Login.tsx); ошибка правила confirmed выводится по-английски, потому что нет lang/ru/validation.php. Нужны русский файл валидации и вывод flash; тест на оба пункта.
- Вход S-0.1: блокировка после 5 неудачных попыток длится 15 минут (SessionController.php:33-39), по макету 10 минут; добавить жёлтое предупреждение и неактивную кнопку на время блокировки; тест на счётчик.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Shell/Auth/Http/SessionController.php`, `app/Shell/Auth/Http/PasswordController.php`, `app/Shell/Integrations/IntegrationsController.php`, `app/Shell/Profile/EmailChanges.php`, `app/Shell/Profile/RequireProfileSettings.php`, `config/features/E1-08.php`, `lang/ru/`, `resources/js/pages/Shell/Auth/Login.tsx`, `tests/Feature/Shell/Auth/`, `tests/Feature/Shell/Integrations/`, `tests/Feature/Shell/Profile/`, `tests/Browser/E1-12C/`, `docs/results/SP-E1-12C.md`.

Отчёт: docs/results/SP-E1-12C.md; локальные тесты задачи, submit и зелёный CI.

## Уточнение интегратора 07.11.2026
- Shell/Auth, Profile и Integrations не используют Shadow; live-наблюдение не является условием исправления входа и профиля. Объём приёмки не сокращён.
