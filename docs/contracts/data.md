# Схема и ownership

Машинный реестр — [registry.json](registry.json). Одна запись таблицы означает одного владельца первоначальной migration/model; другие задачи используют model/port. Новые колонки добавляются отдельной migration в scope задачи, согласованной с владельцем таблицы. Исполнитель не меняет уже merged migration. Это реестр целевого v1, не команда создать все таблицы при bootstrap.

## ID и связи

- Business entities: ULID, PostgreSQL `char(26)`/Laravel ulid-column; JSON/TS string. Foreign column того же типа. Не присваивать идентификатор, полученный из untrusted запроса, новой entity.
- Framework storage (`sessions`, `cache`, jobs, password resets, migrations, Sanctum token primary key) сохраняет native типы выбранной Laravel version. `users.id` — ULID уже в E0-01; tokenable morph ID и user foreign keys совместимы с ULID.
- Старые integer/ULID IDs не переносятся слепо в новый namespace. E9-02 создаёт `migration_batches` и `migration_records` с unique `(source,batch scope,entity,legacy_id)` и mapping к new ID; import идемпотентен на stable source identity, новый batch не создаёт duplicate entity.
- `organizations` — одна installation organization; серверный cabinet ID задаётся local installation config, не клиентом. Нет purge/delete route/column/job. Initial seed создаёт одну организацию идемпотентно; публичная регистрация пользователей не создаёт следующую.
- `brands.organization_id` обязателен; unique `(id,organization_id)`. Brand-owned root имеет `(organization_id,brand_id)` с composite FK на brand. Parent-owned children могут наследовать tenant через обязательный FK с scoped parent lookup; никакого unscoped child lookup по ID.
- `memberships` unique `(organization_id,user_id)`, roles owner/admin/member/viewer, `sales_manager` только у member. brand_ids проверяются внутри организации; пустой список у owner/admin = все, у member/viewer = ни одного (не wildcard).
- Owner transfer transaction меняет memberships и `organizations.owner_user_id` согласованно; невозможно потерять последнего active owner. FK owner nullable только на initial provisioning шаге, затем заполнен.

## Однозначные решения по спорным данным

`knowledge_releases` принадлежит Seller Knowledge, не таблицам RAG M3. `kn_escalation` — единственное имя; `kn_escalation_reasons` не создаётся. Все десять YAML namespaces сохраняют структуру без потери ordered lists. `kn_facts` — атомарные факты с `group_id`; must_not/bridge_hint на группе.

Published releases immutable. `brands.active_knowledge_release_id` — единственный указатель активного релиза; он указывает на published release этого бренда. Старые published releases допустимы в истории; «ровно один» относится к active pointer, не к количеству истории. Publish создаёт новую immutable копию из draft и меняет pointer в одной transaction. Rollback меняет pointer на старый published release, не перезаписывает его. Optimistic draft version обязательна. Nullable active pointer разрешён до первого publish, runtime выдаёт явное knowledge_not_ready, не пустую «успешную» knowledge.

`organization_plan` — единственный источник назначения текущего плана, одна активная запись на организацию, интервалы не пересекаются. `organizations.plan_id` и brand limit cache не создаются как второй источник истины. `plan_limits.value=null` значит не задано; нулевой configured limit — реальное ограничение. Decimal/string коэффициент ИИ nullable; не подразумевать 20 вопросов или цены из макетов.

`channel_accounts`, `channel_inbound_events`, `brand_seller_settings` сначала создаёт E3-04. E5-01 добавляет channel behavior, не вторую channel schema. Settings JSON обновляется patch конкретной секции под optimistic version/transaction; обновление greetings не теряет schedule другого редактора. Credential values шифруются, DTO показывают mask.

`report_publications` — E8-05C: token_hash, report_id, created_by, expires_at nullable, revoked_at nullable. Plain token показывается при создании ссылки, не логируется. Проверки expiry/revoke происходят на каждом запросе и до выдачи cached payload; expiry `now >= expires_at`. Report projection исключает internal LLM text/PII. Public cache не переживает revoke (private/no-store по умолчанию); expired/revoked → 410, unknown → 404.

Support ticket и пользовательские согласия остаются кабинетными функциями; `external_identities`, admin IdentityProvider/PlanProvider, staff console и company purge намеренно отсутствуют. Доступ поддержки 72 часа не проектирует админку: пока нет реализации соответствующего access mechanism, не обещать работающий cross-account доступ.

## Владение shared files

Registry указывает одного coordinator каждой shared области и допустимых serial writers. Например, User model сначала создаёт skeleton, расширяет tenancy и auth; overlap в queue scope обязателен, одновременных writers быть не должно. Generated output меняется через владельца генератора; domain DTO source — через владельца домена. Файлы `routes/features/<TASK>.php`, `config/features/<TASK>.php`, `database/migrations/<TASK>/` принадлежат соответствующему task автоматически и не требуют записи для каждого ещё не существующего файла.

Новые таблицы/события вне registry проходят через интегратора. Часть таблиц framework может измениться с выбранной версией starter; E0-01 документирует реальные native names в registry до создания consumer FK, сохраняя owner и tenancy semantics. Привязки данных из исходника — справочник, не разрешение импортировать production data.

`invitation_issuance_attempts` принадлежит SP-E1-06B и фиксирует организацию, инициатора, HMAC ключа попытки, приглашение и время. Уникальность `(organization_id, actor_user_id, attempt_hash)` и запись попытки вместе с приглашением в одной транзакции предотвращают повторный выпуск по тому же ключу. Секрет ссылки и исходный ключ попытки в таблице не хранятся. Повтор возвращает конфликт без повторной выдачи секрета; новая попытка допускается только по явному действию пользователя. Связи и чтение должны дополнительно проверять соответствие организации и права инициатора; сам receipt не доказывает доставку.
