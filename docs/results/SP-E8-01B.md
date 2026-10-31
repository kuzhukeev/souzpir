# SP-E8-01B — результат для review

Дата: 31.10.2026. Ветка: `codex/sp-e8-01b`. Worktree: `C:/OSPanel/home/souzpir.local/.worktrees/sp-e8-01b`.
Проверенная база claim: `8ed6f5f5eff6d1e8cbaaae5766b61215375958c3`. Commit результата передаётся штатным `scripts/task.py submit`; merge/done выполняет интегратор.

## Реализовано

- Импорт повторно проверяет права организации и бренда и неизменность полного исходного адреса сайта непосредственно перед возвратом результата. При отзыве прав или смене сайта частичные источники и ошибки не возвращаются; ранее сохранённые приватные черновики не удаляются автоматически.
- Перенесены SiteInspector, RobotsPolicy и readiness: исходные группы,20 критериев весом5,16 автоматических +4 pending, интервальная оценка и provenance. Коммерческие примеры/кейсы не становятся подтверждёнными условиями. Отдельный отчёт robots/llms/sitemap/AI-bot rules не добавляет баллы.
- Создан ограниченный HTTP-клиент с проверкой всех DNS A/AAAA/CNAME, запретом внутренних адресов, закреплением IP при сохранении TLS/SNI, без proxy/redirect/reuse. Ограничены размер, время, число переходов и страниц; явная charset безопасно преобразуется в UTF-8. Загрузка по умолчанию выключена.
- Перенесены извлечение сайта и импорт в приватные версионированные черновики собственного модуля. Авторизация vision.settings.write, текущий tenant, активность organization/brand/membership, origin и права повторно проверяются; идентичные версии переиспользуются. Запись сопровождается redacted audit, не передаёт текст ИИ и не меняет Seller release.
- Исправлены выявленные регрессиями пустой sitemap, нечисловые границы NaN, пустой/trailing-dot host, скрытый H1 в заголовке, непроверенный source URL/fingerprint и сохранение после отзыва доступа. Наружная транзакция для импорта/записи запрещена.
- По замечаниям независимого review устранён конфликт опций Guzzle/CurlFactory; закрепление DNS сохранено. Явно скрытые HTML-элементы исключаются до извлечения фактов и ссылок в аудите и импорте.
- На повторном review закрыта утечка URL и чужих исключений в транспортной и публичной границах; query-адреса не загружаются и не используются как ссылки на источники, fragment исключён. Выданные результаты содержат точные пути и извлечённый текст и считаются потенциально чувствительными.
- Общие контракты, registry, модели, manifest/lock, bootstrap, routes и исходники других задач не изменены. Новые таблицы не добавлены. Реализация/тесты/документы только внутри scope карточки.

## Выполненные проверки

Повторная проверка исправления отзыва и смены сайта: полный профиль выше — 172 теста, 939 проверок; включая отзыв после последней страницы и смену пути на том же домене. Сетевые вызовы подменены `Http::fake()`.

Из task worktree, изолированное `.env.testing`, PostgreSQL `souzpir_test_sp_e8_01b`:

```powershell
& ./infra/osp/php.ps1 vendor/bin/pest tests/Feature/Vision/SiteAudit tests/Unit/Vision/SiteAudit tests/Feature/Smoke/FoundationTest.php --compact
# 169 passed, 927 assertions, exit 0 (28.40s; zend.exception_ignore_args=0)
& ./infra/osp/php.ps1 vendor/bin/pint --test app/Vision/SiteAudit config/features/E8-01B.php tests/Feature/Vision/SiteAudit tests/Unit/Vision/SiteAudit
# passed
& ./infra/osp/php.ps1 artisan config:cache --env=testing
& ./infra/osp/php.ps1 artisan config:show features.E8-01B --env=testing
& ./infra/osp/php.ps1 artisan config:clear --env=testing
# cache/show/clear successful; binding registered, crawl_pages=5, fetch_enabled=false
```

Состав включает донорские регрессии, HTML fixtures, network/DNS/charset, приватный импорт и foundation smoke; после review добавлены проверки скрытых поддеревьев/ссылок и настоящего CurlFactory без выполнения сетевого запроса, безопасных исключений и политики query на входе, redirect и импорте. Проверены разрешения и изоляция брендов, robots/foreign redirect, отзыв membership во время fetch, audit failure/orphan recovery, идемпотентность/новая версия, stale fingerprint, транзакционные границы, скрытый контент. Истинный путь Http fake → crawler → parser → score и importer → validation/permission → storage → audit проверен.

HTTP в тестах подменён с preventStrayRequests; DNS — fake resolver/records seam. Live DNS/HTTP/ИИ/mail/Telegram/webhook не выполнялись. Золотые LLM fixtures не изменялись; эти тесты не доказывают эквивалентность реального LLM-run. Проверка whitespace/scope перед commit выполняется дополнительно.

## Среда

Собственные preview/test БД `souzpir_sp_e8_01b` / `souzpir_test_sp_e8_01b`, ограниченная роль без superuser/createDB/createRole/replication/bypassrls. PUBLIC-доступ на новые БД отозван; роль не имеет CONNECT к рабочей БД. Env/credentials, vendor, cache/storage/temp находятся только в игнорируемых файлах worktree. Mail=log, AI/delivery=fake, queue=sync, scheduler=false; уникальные instance/prefix/cookie/queue. Composer установил139 locked packages. Службы и их HBA/config не менялись; у существующего локального PG сохранён общий trust fallback (не заявляется SCRAM-изоляция от других локальных процессов).

## Ограничения и точный запрос интегратору

1. В registry нет M0 KnowledgeDocument/KnowledgeVersion/KnowledgeProposal донора. Импорт сохраняет только приватные draft sources: proposal_id=null, confirmed=0; approve/publication и применение в общей базе знаний не реализованы. Для подключения нужен согласованный адаптер source draft → общий review/proposal/import API с отдельным ownership и, при необходимости, расширением registry/schema. Чужие модели и параллельный Seller knowledge pipeline не дублировались.
2. Audit event служит commit-marker файла; политика очистки audit должна сохранить эти markers либо сначала согласовать отдельный долговечный marker. При сбое до audit commit orphan не читается. Это не распределённая атомарная транзакция и не автоматическая очистка файлов.
3. Только публичный HTML без JavaScript; страницы с query и ссылки, требующие query, намеренно не обходятся; в том числе контактные WhatsApp-ссылки с query не подтверждаются. Путь сохраняется как provenance и может быть чувствительным, как и текст/JSON-LD; результаты запрещено без отдельной защиты писать в логи или публиковать. Реальный сетевой транспорт не запускался; CurlFactory конфигурируется реальными параметрами без выполнения запроса; полноценный сетевой путь не проверен. Явные атрибуты hidden/aria-hidden и простые inline display:none/visibility:hidden исключаются, но внешние таблицы стилей, динамический CSS и JavaScript не интерпретируются. DNS timeout наследуется от resolver ОС; HTTP/deadline не прерывает системный DNS принудительно. Unknown charset/invalid text, compressed/binary/неподдержанный MIME отвергаются. Каталог bots — снимок донора, не повторная live-верификация.
4. Группы критериев сохранены до Q-03/R-V-11. Четыре semantic/LLM проверки остаются unknown/pending. Числа технических ограничений не являются тарифными лимитами.
5. Карточка инфраструктурная (`screenIds=[]`): UI, screenshots, темы и viewport-проверки неприменимы; фиктивные экраны не добавлены. UI запуска/отчётов принадлежит задачам-потребителям. В main не объединено, done не устанавливается.

Подробное описание критериев и API: `docs/modules/E8-01B.md`.

## Read-only provenance донора

Исходники читались из `C:/OSPanel/home/souzpir.local/.local/references/eyon-pilot`, оригиналы не менялись.

| Файл | SHA256 |
|---|---|
| `app/Services/Audit/SiteInspector.php` | `31bafdcf39c7695a93378e250f81fd1f4af3c899b933333409e49f74a04c275d` |
| `app/Services/Audit/RobotsPolicy.php` | `30f97de668a6f1a2a7e946729824ceacc519db8efae8c108abffb1f7cce84404` |
| `app/Services/Audit/Metrics.php` | `00818bb90f0a705039fbc703127a19d929b86e6554d7e043e9cf7798b42e5ee5` |
| `app/Services/Knowledge/SiteKnowledgeImporter.php` | `ef1dbfa485de78b337fa60b99814e6248b3be9af3ceb1cfe080721a4d6f38d2d` |
| `app/Services/Knowledge/SiteKnowledgePage.php` | `69b6b11bc35ca140f86af3454488accaacf4fc5a798852a5311246c754662371` |
| `tests/Feature/SiteInspectorTest.php` | `b2abf061984ec007b671777fb4f6644afaa1abc022849286430311712375cd28` |
