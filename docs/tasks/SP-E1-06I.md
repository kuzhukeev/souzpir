# SP-E1-06I · Команда: карточка участника, роли, приглашение

**Зачем:** решение владельца от 03.11.2026 по повторному аудиту 160 экранов закрыть оставшиеся расхождения с макетами; экраны S-3.2, S-3.3, S-3.4.
**Часть чего:** остаток после SP-E1-06…06H.
**Зависимости:** SP-E1-01I, SP-R-01, SP-E1-01H (дождаться merge).

- Экран S-3.2 «Пользователь» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [20]): пояснения под выбором роли; переключателей модулей v2; ссылок «Настроить отчёты»/«Настроить уведомления» в блоке «Получает»; встроенной «Активности» (последние 20) — только ссылка; фото-аватара (иконка). Где сейчас: resources/js/pages/Shell/Team/Show.tsx:60,132,133,146; resources/js/features/Shell/Team/EditMember.tsx:41.
- Экран S-3.3 «Роли» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [21]): блока с описанием 5 ролей (только иконка и название). Где сейчас: resources/js/pages/Shell/Team/Roles.tsx:39-49,70,190-215; resources/js/features/Shell/Team/roleMatrix.ts:12-45.
- Экран S-3.4 «Приглашение» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [22]): блока «Доступ к модулям» v2; кнопки «Готово»; (роль — select, не радиокнопки). Где сейчас: resources/js/features/Shell/InvitationIssuance/InvitationDialog.tsx:467.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Shell/Team/`, `app/Shell/InvitationIssuance/`, `resources/js/pages/Shell/Team/`, `resources/js/features/Shell/Team/`, `resources/js/features/Shell/InvitationIssuance/`, `routes/features/E1-06.php`, `tests/Feature/Shell/Team/`, `tests/Feature/Shell/InvitationIssuance/`, `tests/Browser/E1-06I/`, `docs/results/SP-E1-06I.md`.

Отчёт: docs/results/SP-E1-06I.md; локальные тесты задачи, submit и зелёный CI.
