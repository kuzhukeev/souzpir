# SP-E5-04H · Продавец: очередь на одобрение — экран над /seller/turns/pending

**Зачем:** решение владельца от 03.11.2026: задач должно хватать на 100 % закрытие экранов по функционалу; экраны D-2.4 остались без карточки после частичных сдач.
**Часть чего:** экран D-2.4 никогда не планировался: SP-C-02 сделал только JSON-маршруты /seller/turns/pending и review; карточка заводит экран очереди над ними.
**Зависимости:** SP-E5-04F (дождаться merge).

- Пути в «Где сейчас» без каталога даны относительно resources/js/pages/Seller/ и app/Seller/.
- Экран D-2.4 «Очередь на одобрение» соответствует макету из матрицы по пунктам (журнал docs/sources/02b_mockup_journal.md [42]): экран /seller/dialogs/pending (Inertia) со списком ходов, ждущих одобрения: клиент, канал, текст ответа, время ожидания; действия «Отправить», «Отправить с правкой» (редактор текста перед отправкой), «Отклонить»; пагинация; мобильная версия; данные через существующие PendingSellerTurnsController и RequestSellerTurnReviewController без обхода защищённого контура, JSON-маршруты сохраняются. Где сейчас: routes/features/C-02.php:11-16; app/ProtectedOperations/PendingSellerTurnsController.php; app/ProtectedOperations/RequestSellerTurnReviewController.php.
- Каждый пункт покрыт Feature- или Browser-тестом; данные только текущего бренда, проверены права ролей; состояния пусто / загрузка / ошибка как на макете; в отчёте скриншоты каждого экрана рядом с макетом.
- Без живых и платных внешних вызовов без разрешения владельца; если пункт макета противоречит ТЗ или требует решения владельца, исполнитель не выдумывает, а записывает вопрос в docs/decisions-pending.md и делает остальное.
- Сдача частями: если что-то из пунктов не сделано, в отчёте раздел «Остаток» перечисляет невыполненные пункты по экранам; интегратор принимает такой отчёт только вместе с заведённой карточкой-продолжением на этот остаток (правило в AGENTS.md).

Scope: `app/Seller/Dialogs/`, `app/ProtectedOperations/PendingSellerTurnsController.php`, `app/ProtectedOperations/RequestSellerTurnReviewController.php`, `resources/js/pages/Seller/Dialogs/`, `routes/features/C-02.php`, `routes/features/E5-04.php`, `tests/Feature/Seller/Dialogs/`, `tests/Browser/E5-04H/`, `docs/results/SP-E5-04H.md`.

Отчёт: docs/results/SP-E5-04H.md; локальные тесты задачи, submit и зелёный CI.
