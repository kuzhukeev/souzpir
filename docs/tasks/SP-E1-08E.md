# SP-E1-08E · Профиль: уведомления и смена почты

**Зачем:** решение владельца от 03.11.2026 по повторному аудиту 160 экранов закрыть оставшиеся расхождения с макетами; экраны S-7.1, S-7.3.
**Часть чего:** остаток после SP-E1-08D и SP-E1-12C.
**Зависимости:** SP-E1-08D, SP-E1-11B, SP-E1-12C, SP-E1-08C, SP-R-01 (дождаться merge).

- Экран S-7.1 «Уведомления (личные)» (остаток после SP-E1-08D; журнал docs/sources/02b_mockup_journal.md [31]): переключатель «Сводка вместо каждого события»; тихие часы и сводка реально влияют на доставку (сейчас timingAvailable=false и вместо формы черновик-заглушка); «Сохранить» неактивна без изменений. Где сейчас: resources/js/pages/Shell/Profile/Notifications.tsx:127-130,382; resources/js/features/Shell/Profile/TimingDraftForm.tsx:35; app/Shell/Profile/NotificationController.php:42.
- Экран S-7.3 «Профиль пользователя» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [33]): смены почты в production (принудительно выключена, кнопка disabled). Где сейчас: resources/js/pages/Shell/Profile/Profile.tsx:224-237,284-302,309,318-322,327-329; app/Shell/Profile/EmailChanges.php:31; config/features/E1-08.php.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Shell/Profile/`, `resources/js/pages/Shell/Profile/`, `resources/js/features/Shell/Profile/`, `routes/features/E1-08.php`, `tests/Feature/Shell/Profile/`, `tests/Browser/E1-08E/`, `docs/results/SP-E1-08E.md`.

Отчёт: docs/results/SP-E1-08E.md; локальные тесты задачи, submit и зелёный CI.
