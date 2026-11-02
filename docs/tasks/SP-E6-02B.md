# SP-E6-02B · Подключить приветствия к первому ходу

**Зачем:** D-6.2, макет 62: настройки приветствий должны влиять на открытие диалога, а пустое значение — давать стандартную генерацию.

**Часть чего:** SP-E6-02 внесла экран и сохранение; здесь остаются GreetingResolver, подключение к `open` и TurnTest. После этой карточки остатка E6-02 нет.

Размер 1–3 часа. Зависимости: SP-E6-02, SP-E3-08. Scope: `app/Seller/Runtime/`, `app/Seller/Settings/Greetings/`, `tests/Feature/SellerRuntime/Turns/`, `tests/Feature/Seller/Settings/Greetings/`, `docs/results/SP-E6-02B.md`. Не менять golden fixtures. Проверить пять каналов, бренд, пустой текст и fake-генерацию.
