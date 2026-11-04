# SP-E1-04C · Компания и модули

**Зачем:** решение владельца от 03.11.2026 по повторному аудиту 160 экранов закрыть оставшиеся расхождения с макетами; экраны S-2.1, S-2.4.
**Часть чего:** остаток после SP-E1-04/04B; «Опасная зона» использует сценарий удаления из SP-E1-11B.
**Зависимости:** SP-E1-11B (дождаться merge).

- Экран S-2.1 «Компания» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [15]): блока «Опасная зона» / «Удалить компанию». Где сейчас: resources/js/pages/Shell/Organizations/Company.tsx:18.
- Экран S-2.4 «Модули бренда» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [18]): «Подключён с <дата>»; списка «что входит»; текста «данные сохранятся 90 дней» (есть «Сохранённые данные останутся» без срока). Где сейчас: resources/js/pages/Shell/Organizations/Modules.tsx:16; resources/js/features/Shell/Brands/Modules.tsx.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.

Scope: `app/Shell/Organizations/`, `app/Shell/Brands/`, `resources/js/pages/Shell/Organizations/`, `resources/js/features/Shell/Organizations/`, `resources/js/features/Shell/Brands/`, `routes/features/E1-04.php`, `tests/Feature/Shell/Organizations/`, `tests/Feature/Shell/Brands/`, `tests/Browser/E1-04C/`, `docs/results/SP-E1-04C.md`.

Отчёт: docs/results/SP-E1-04C.md; локальные тесты задачи, submit и зелёный CI.
