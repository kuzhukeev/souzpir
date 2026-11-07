# SP-E8-11B · Отчёт Видения: аккордеон, публикация, публичная страница

**Зачем:** решение владельца от 03.11.2026 закрыть незапланированные расхождения с макетами по итогам аудита 160 экранов; экраны V-5.9, V-5.10, V-5.P.
**Часть чего:** остаток после SP-E8-05/05B/05C.
**Зависимости:** SP-E8-11 (дождаться merge).

- Экран V-5.9 «Методика» соответствует макету из матрицы по пунктам (макет из матрицы docs/sources/02_screens_matrix.md; записи в журнале нет): аккордеон. Где сейчас: Index.tsx:80.
- Экран V-5.10 «Поделиться (модал)» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [106]): модал на отчёте; QR; «Разрешить PDF»; реализовано отдельной страницей. Где сейчас: Publications/Index.tsx:28-45.
- Экран V-5.P «Публичный отчёт» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [106], [107]): Главные выводы; топ-5; счётчики; оценка сайта; Три действия; Методика (есть только KPI). Где сейчас: PublicReportController.php; public-report/index.blade.php.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Vision/Reports/`, `app/Vision/Publications/`, `resources/js/pages/Vision/Reports/`, `resources/js/pages/Vision/Publications/`, `resources/views/public-report/`, `resources/js/ssr.tsx`, `routes/features/E8-05.php`, `routes/features/E8-05C.php`, `tests/Feature/Vision/Reports/`, `tests/Feature/Vision/Publications/`, `tests/Browser/E8-11B/`, `docs/results/SP-E8-11B.md`.

Отчёт: docs/results/SP-E8-11B.md; локальные тесты задачи, submit и зелёный CI.
