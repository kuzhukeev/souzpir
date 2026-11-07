# SP-E1-01I · Вход, регистрация, восстановление пароля, приглашение: остаток

**Зачем:** решение владельца от 03.11.2026 по повторному аудиту 160 экранов закрыть оставшиеся расхождения с макетами; экраны S-0.1, S-0.2, S-0.4, S-0.5.
**Часть чего:** остаток после SP-E1-01H и SP-E1-12C.
**Зависимости:** SP-E1-01H, SP-E1-12C, SP-E1-08C, SP-E1-08D (дождаться merge).

- Экран S-0.1 «Вход» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [1]): ссылок «Условия» и «Конфиденциальность» в подвале (только «© EYON»); блокировка 15 мин после 5 попыток вместо 10 мин по макету; жёлтого предупреждения о блокировке и заблокированной кнопки. Где сейчас: resources/js/pages/Shell/Auth/Login.tsx; resources/js/features/Shell/Auth/AuthFrame.tsx:50; app/Shell/Auth/Http/SessionController.php:33-39.
- Экран S-0.2 «Регистрация» (остаток после SP-E1-01H; журнал docs/sources/02b_mockup_journal.md [2]): маска телефона +7 и текст про OpenRouter из макета. Где сейчас: resources/js/pages/Shell/Auth/Register.tsx:64-71,72-83,92.
- Экран S-0.4 «Восстановление пароля (запрос / новый пароль)» (остаток после SP-E1-12C; журнал docs/sources/02b_mockup_journal.md [4], [5]): экран «Пароль изменён» с кнопкой «Войти в кабинет» и состояния ошибок как на макете. Где сейчас: app/Shell/Auth/Http/PasswordController.php:146,191; resources/js/pages/Shell/Auth/Login.tsx.
- Экран S-0.5 «Принятие приглашения» (остаток после SP-E1-01H; журнал docs/sources/02b_mockup_journal.md [6]): состояние, когда приглашение открыто под другой почтой: текст и кнопка «Выйти из аккаунта». Где сейчас: resources/js/pages/Shell/Auth/Invitation.tsx:33-40; app/Shell/Auth/Http/InvitationController.php:37,155-158; routes/features/E1-01.php:33,35.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Shell/Auth/`, `app/Shell/InvitationIssuance/`, `resources/js/pages/Shell/Auth/`, `resources/js/features/Shell/Auth/`, `routes/features/E1-01.php`, `tests/Feature/Shell/Auth/`, `tests/Feature/Shell/InvitationIssuance/`, `tests/Browser/E1-01I/`, `docs/results/SP-E1-01I.md`.

Отчёт: docs/results/SP-E1-01I.md; локальные тесты задачи, submit и зелёный CI.

## Дополнение владельца от 05.11.2026
- красный алерт «Неверная почта или пароль»;
- общая ошибка регистрации «Проверьте поля и подтвердите обязательные согласия»;
- при expired/revoked/accepted переход на /invitations/state (InvitationController.php:80-86).
