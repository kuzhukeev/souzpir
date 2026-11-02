# SP-E5-02C · Виджет: сессия посетителя и сообщения

**Размер:** 1–3 часа; функциональный остаток, не сверх ТЗ.

**Зачем:** Backlog E5-02 и экраны D-9.2, D-9.3, D-9.4; обязательный остаток после частичной сдачи SP-E5-02.

**Часть чего:** SP-E5-02. После C функционального остатка SP-E5-02 нет.

Зависимости: SP-E5-02B, SP-E3-08, SP-E3-08B, SP-E3-09, SP-E5-03C, SP-E4-09. После API-канала использовать ту же защищённую точку входа для публичной сессии посетителя.

Scope: `app/Seller/Channels/Widget/`, `app/ProtectedOperations/SellerFollowup/`, `app/Seller/Runtime/`, `resources/widget/`, `resources/js/pages/Seller/Channels/Widget/`, `routes/features/E5-02.php`, `tests/Browser/E5-02/`, `tests/Feature/Seller/Channels/Widget/`, `tests/Feature/SellerRuntime/Turns/`, `tests/Unit/Seller/Channels/Widget/`, `config/features/E5-02.php`, `database/migrations/E5-02C/`, `resources/js/types/Seller/Channels/Widget/`, `docs/results/SP-E5-02C.md`. Сгенерированные типы разрешены общим правилом.

Связать защищённую сессию посетителя и сообщения виджета с seller runtime и сохранённым согласием/контактом. Если runtime требует сессию сотрудника, расширить защищённую точку входа на проверенную публичную сессию посетителя; не обходить runtime прямой записью сообщений.

Приёмка: Fake/HTTP/browser e2e №7: начало сессии, ход, повтор, чужой origin и бренд, недоступный runtime. Живые внешние вызовы в тестах запрещены.

Чужие модули не менять; итоговый отчёт до 30 строк с тестами, долгом и решениями.

Сквозная приёмка владельца: после импорта знаний прототипа и включения виджета на разрешённом сайте молчание посетителя запускает автооткрытие через behavior.delay_seconds (по умолчанию 8–10 с); первый ход intro_and_ask выполняет runtime, а не статичный текст. Приветствие канала «Сайт» с {страница} и сведения о странице передаются Responder; использовать open_greeting_widget без упоминания заявки. Fake-LLM browser e2e проверяет имя из persona, первый вопрос по слоту, отсутствие слова «заявка», второй ход через runtime Песочницы, последовательность city → purpose → experience → outlet_type, техники, запреты и передачу менеджеру.
