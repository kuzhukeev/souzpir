# SP-E1-08E · Профиль: уведомления и смена почты

**Зачем:** решение владельца от 03.11.2026 по повторному аудиту 160 экранов закрыть оставшиеся расхождения с макетами; экраны S-7.1, S-7.3.
**Часть чего:** остаток после SP-E1-08D и SP-E1-12C.
**Зависимости:** SP-E1-08D, SP-E1-11B, SP-E1-12C, SP-E1-08C (дождаться merge).

- Экран S-7.1 «Уведомления (личные)» (остаток после SP-E1-08D; журнал docs/sources/02b_mockup_journal.md [31]): переключатель «Сводка вместо каждого события»; тихие часы и сводка реально влияют на доставку (сейчас timingAvailable=false и вместо формы черновик-заглушка); «Сохранить» неактивна без изменений. Где сейчас: resources/js/pages/Shell/Profile/Notifications.tsx:127-130,382; resources/js/features/Shell/Profile/TimingDraftForm.tsx:35; app/Shell/Profile/NotificationController.php:42.
- Экран S-7.3 «Профиль пользователя» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [33]): смены почты в production (принудительно выключена, кнопка disabled). Где сейчас: resources/js/pages/Shell/Profile/Profile.tsx:224-237,284-302,309,318-322,327-329; app/Shell/Profile/EmailChanges.php:31; config/features/E1-08.php.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Shell/Profile/`, `resources/js/pages/Shell/Profile/`, `resources/js/features/Shell/Profile/`, `routes/features/E1-08.php`, `tests/Feature/Shell/Profile/`, `tests/Browser/E1-08E/`, `docs/results/SP-E1-08E.md`.

Отчёт: docs/results/SP-E1-08E.md; локальные тесты задачи, submit и зелёный CI.


## Остаток 08.11.2026 (планировщик, запуск 20261107-225307)
- S-7.1 (макет `mockups S-7.1`, журнал 02b [31]): личные тихие часы и «Сводка вместо каждого события» реально применяются в основном контуре личных уведомлений `app/Shell/Notifications/`: событие в тихие часы откладывается до их окончания (статус «Отложено» в истории), при включённой сводке события копятся и уходят одним сообщением в день с группировкой по бренду и каналу.
- Форма на экране: переключатель «Сводка вместо каждого события», тихие часы вместо черновика-заглушки (`timingAvailable=true`), «Сохранить» неактивна без изменений.
- Доставка только через существующие fake-транспорты; боевые почта/Telegram не включаются. Тесты: откладывание в тихие часы, сводка за день, изоляция пользователя/бренда, ошибка ввода времени. Общие уведомления компании (S-5.3) не трогать.
- Смена почты в S-7.3 остаётся за флагом (ждёт владельца), в задачу не входит.

Scope: `app/Shell/Notifications/`, `app/Shell/Profile/NotificationController.php`, `resources/js/pages/Shell/Profile/Notifications.tsx`, `resources/js/features/Shell/Profile/TimingDraftForm.tsx`, `database/migrations/E1-08E/`, `tests/Feature/Shell/Notifications/QuietHoursDigestE108ETest.php`, `tests/Browser/E1-08E/`, `docs/results/SP-E1-08E.md`.
