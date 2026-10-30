# Контракты кабинета, версия 1

Принятый baseline задачи SP-C-01, 31.10.2026. Эти документы задают границы нового приложения; они не являются готовой реализацией интерфейсов. Исполняемые классы появляются в задачах-владельцах из [registry.json](registry.json). Изменение сигнатуры или ownership после merge требует решения интегратора и обновления потребителей.

Сначала читать:

1. [loaders.md](loaders.md) — точное задание bootstrap SP-E0-01: подключение независимых features и изоляция окружений.
2. [core.md](core.md) — DTO, HTTP, ошибки, concurrency, интерфейсы и расширения runtime.
3. [data.md](data.md) и [registry.json](registry.json) — идентификаторы, таблицы, зависимости и единственные владельцы схем/событий/shared files.

Одна организация, несколько брендов. Нет S-0.7, удаления организации, admin console или синхронизации с будущей головной админкой. TTL и отзыв публичных отчётов обязательны. Неизвестные тарифные значения остаются null. Актуальные решения пользователя выше исторических документов.

Текущая контрольная точка пользователя: поднятая БД и первые свёрстанные макеты, затем показ результата и ожидание команды продолжать. Наличие в контрактном реестре будущей таблицы или интерфейса не означает разрешение реализовать следующую задачу до этой команды. Этот task не запускает БД, сеть, доставку, ИИ или другие задачи.

## Граф зависимостей кода

Tenancy не зависит от Seller/Vision. Ai, Audit, Notifications и Plans используют Tenancy, но не конкретные Seller/Vision models. Knowledge строит неизменяемый snapshot без зависимости от Engine. Engine получает snapshot, Ai и ports через Runtime; он не запрашивает Eloquent settings самостоятельно. Channels вызывает Runtime и реализует Delivery; Runtime не импортирует конкретный Telegram/Widget adapter. Vision использует Tenancy/Ai/Plans. Shell dashboard получает UsageReader/attention DTO от модулей и не обращается к их таблицам напрямую.

Регистрация implementations — в bootstrap feature registry. Это composition root; обратная регистрация adapter в runtime не является обратным import из runtime к adapter. Контракт принадлежит поставщику, если не указан отдельный владелец; consumers не копируют DTO в свои пространства имён.

## Проверка реестра

Из корня worktree:

```powershell
& 'C:\Users\eralash\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe' docs/contracts/validate.py
```

Проверяется JSON, уникальность таблиц/событий/контрактов/shared paths, существование владельцев в плане, FK parents, завершённость core table vocabulary и отсутствие циклов доменных imports. Проверка не доказывает работу будущих migrations/HTTP/LLM. Это проверяют задачи реализации и их integration tests.
