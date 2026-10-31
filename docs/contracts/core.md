# DTO, HTTP и границы runtime v1

Это спецификация сигнатур для implementations соответствующих задач, не требование создать весь код в skeleton. Внутри PHP типы именуются PascalCase/camelCase, на проводе JSON — snake_case; TS DTO генерируются из PHP, не описываются вручную второй раз. ULID — opaque string, время — ISO-8601 UTC, money — decimal string либо целое число micros, никогда float в контракте учёта.

## Tenancy и HTTP

`App\Tenancy\TenantContext` — scoped immutable value `{organizationId, brandId: ?ULID, actorId: ?ULID, membershipId: ?ULID, source: session|token|job|public}`. Организация задаётся server-side cabinet installation; пользователь не выбирает её через header или форму. Brand определяется сессией либо brand token; `X-Brand-Id`, если поддерживается, только проверяется против разрешённого scope. Отсутствие обязательного контекста закрывает доступ. Job восстанавливает полный context по persisted IDs и очищает его в finally; один worker не переносит context к следующему job.

`POST /brand/switch {brand_id}` проверяет membership и принадлежность организации, обновляет session, возвращает redirect. Фронт удаляет Query cache старого brand, не сохраняет dirty form или optimistic messages между брендами. Scoped model lookup выполняется до policy: чужой ID → 404; известный объект текущего бренда без права → 403. Ошибка FK из чужого бренда в форме → 422 без раскрытия имени объекта.

Успешный API: `{data: T, meta?: {request_id, next_cursor?, version?}}`; списки — `data: T[]`, cursor opaque. Inertia возвращает page props и обычные redirects после формы; JSON-ошибки не заменяют штатную Inertia error bag.

```json
{"message":"Нет права изменять настройки","code":"permission_denied","errors":{},"request_id":"req-001"}
```

| Status | Пример | Гарантия |
|---|---|---|
| 401 | нет session/отозван bearer token | нет tenant data |
| 403 | viewer меняет факт своего бренда | мутации/аудита успешного изменения нет |
| 404 | чужой conversation/release/brand | тот же вид ответа, что для несуществующего ID |
| 409 | `{code:"version_conflict", errors:{version:["Данные изменились"]}}` | сохранён актуальный draft; клиент обновляет данные перед повтором |
| 409 | idempotency key уже использован с другим body | повторная работа и второй LLM-call не начинаются |
| 422 | `{code:"validation_failed", errors:{brand_id:["Недопустимое значение"]}}` | форма показывает ошибку поля; чужой ID не раскрыт |
| 410 | expired/revoked public report token | нет report payload; отсутствующий token → 404 |
| 429 | rate limit | Retry-After; не повторять неконтролируемо |

Секреты канала, raw LLM response, system prompts и cost_usd не входят в generic public DTO. API token показывается только при создании; в последующих responses маска. Код request_id не содержит credential.

Shared props E0-08:

```ts
interface SharedPropsV1 {
  auth: { user: { id: string; name: string; email: string } | null };
  organization: { id: string; name: string } | null;
  brand: { id: string; name: string; theme: 'confectionery'|'pyro'|'neutral' } | null;
  brands: Array<{ id: string; name: string }>;
  modules: { seller: boolean | null; vision: boolean | null };
  can: Record<string, boolean>;
  unread: number | null;
  request_id: string;
}
```

Until actual module entitlements and unread notifications are connected, each module flag and unread are null (unknown); UI must not interpret null as disabled or zero. Future integration must provide tenant-scoped authoritative sources.

Public SSR uses separate props and never serializes SharedPropsV1. Permissions source — E0-03. Technical roles remain owner/admin/member/viewer, sales_manager valid only for member. Minimal action vocabulary: `organization.manage`, `team.manage` (owner/admin); `billing.manage` (owner); `seller.dialogues.operate` (owner/admin/editor/manager); `seller.knowledge.write` (owner/admin/editor); `seller.settings.write` (owner/admin); `vision.quiz.write` (owner/admin/editor); `vision.actions.write` (owner/admin/editor, при действующем доступе к бренду и модулю); `vision.settings.write` (owner/admin); `reports.read` (brand-authorized roles). Owner is not omitted from an action just because a source table writes “admin”. This explicit baseline resolves vague “editor can edit all modules”; E0-03 may refine actions with documented tests, not silently elevate a permission.

## Ports и минимальные сигнатуры

DTO constructors carry the described named fields; domain-specific extra fields require version review. `v1` is schema version, not URI version inheritance.

| Port / owner | Signature and result |
|---|---|
| KnowledgeSnapshot / E3-01 | `all(string $file): array`, existing prototype accessors, `toArray(): array`, `releaseId(): string`, `checksum(): string`; immutable |
| KnowledgeRepository / E3-01 | `published(TenantContext): KnowledgeSnapshot`, `forRelease(TenantContext, string $id, bool $allowDraft): KnowledgeSnapshot`; draft allowed only authorized sandbox |
| AiGateway / E0-05 | `complete(AiRequest $request, AiContext $context): AiResult`; fake and record implement identical shape |
| ConversationRepository / E3-04 | `find(TenantContext, string $id): ConversationState`, `claimTurn(TenantContext, TurnCommand): TurnClaim`, `commitTurn(TurnClaim, TurnOutcome): void`; version/claim checks atomic |
| SellerRuntime / E3-03B | `turn(TenantContext, TurnCommand): TurnOutcome`, `open(TenantContext, OpenCommand): TurnOutcome`; owns orchestration, not concrete transport |
| ChannelDelivery / E3-03B contract, channel tasks adapters | `send(DeliveryCommand): DeliveryReceipt`; command references persisted outbound message + channel + idempotency key |
| PlanLimits / E0-06 | `get(TenantContext, string $key): LimitValue` where `{value: decimal-string|null, unit: string, configured: bool}`; unset does not block |
| UsageReader / E0-06 contract | `read(TenantContext, UsageQuery): UsageSnapshot`; module adapters registered by E7-04 and E8-07 |
| AuditWriter / E0-04 | `record(TenantContext, AuditEntry): void`; redacted before/after, actor, action, subject ID, event/request ID; same transaction as business change |
| NotificationDispatcher / E0-04 | `enqueue(DomainEvent): void`; after business commit, recipient policy + preferences, retries/dedup |
| VisionReadModel / E8-01 | `report(TenantContext, string $reportId): InternalReportV1`; public projection separate E8-05C |
| ExportContributor / E1-11 | `module(): string`, `stream(TenantContext, ExportQuery): iterable<ExportRecord>`; contribution only its module, private storage, bounded cursor batches |

`ConversationRepository::claimTurn` возвращает также `uncertain`, когда исход
ранее начатого хода требует ручной проверки. Это не `finished`, не успешный и не
подтверждённо отсутствующий эффект: `outcome` и token отсутствуют. Новый ход того
же диалога блокируется, пока неопределённое состояние не разрешено доверенной
процедурой, которой пока нет. При фиксации нового client message активный ход
переходит в `uncertain` в той же PostgreSQL-транзакции; повторная доставка уже
сохранённого входа сама по себе состояние не меняет. Исторические завершённые
ходы сохраняют прежний replay. Это не готовый финализатор и не разрешение на
повтор внешнего эффекта.

**Точная совместимость знаний:** реальный прототип `Knowledge::all` требует `string $file`, вопреки сокращённому `all()` в пакетных документах. `toArray()` возвращает все десять namespaces: conditions, facts, triggers, guard_rules, policy, intents, escalation, persona, fewshot, closed_topics. Equivalence сравнивает `snapshot->all($file)` с original `Knowledge->all($file)` для каждого `Knowledge::FILES`; порядок массивов, влияющий на Policy/fewshot, сохраняется. Нормализуется порядок object keys, не порядок list элементов.

AiContext: `{organizationId, brandId, purpose, family:seller|vision, objectType, objectId, idempotencyKey, dataClasses[], attempt, parentCallId?}`. `purpose` у продавца остаётся extractor/responder/guard/summary/simulator/judge; budget family отдельно, чтобы не потерять исходный purpose ради префикса eyon `seller.*`. Adapter явно сопоставляет legacy purposes/budget scopes; не копировать требование пилотного budget ID как универсальную бизнес-константу.

AiRequest: OpenAI-shaped `messages`, `model`, `temperature`, `responseFormat?`, `tools[]?`, `toolChoice?`, `maxTokens?`, prompt version/checksum. AiResult: `{content:?string, toolCalls:list, usage:{promptTokens,completionTokens}, model, latencyMs, costMicros:?int, aiCallId, error:?{code,retryable}}`. `content=null` при tool-calls допустим. Полный raw response только в разрешённом внутреннем record storage. Fake fixture missing — ошибка теста, не пустой успешный ответ. Responder adapter сохраняет полный tool call ID/name/arguments и семантику LlmResult::json; accounting не использует float.

SP-C-02 добавляет отдельный внутренний fake-only путь стадии summary: созданная
сервером запись закрепляет текущие историю, turn, сессию и фактический запрос;
связь с `ai_calls` фиксируется в той же транзакции, в которой создаётся вызов,
до обращения к драйверу. Помимо XID используется случайный nonce, созданный
триггером и доступный только в текущей транзакции: повтор XID после логического
восстановления не позволяет прикрепить прежний вызов. Dispatch возвращает только
`completed`, `in_progress` или `uncertain`, не содержимое и не ID вызова;
обычный `complete` не воспроизводит привязанный защищённый вызов. Чтение
подтверждённого ответа требует активной транзакции и повторно проверяет
сессию, доступ, fence, запрос и сохранённый результат. Идентификатор стадии,
claim или публичный `AiResult` не являются авторизацией либо самостоятельным
доказательством. Это не runtime или finalizer: реальный драйвер в этом пути
закрыт, `commitTurn` не изменён; неопределённый исход не отправляется повторно.

UsageQuery: `{from,to,metrics:list<string>}`. UsageSnapshot: `{as_of, metrics:{metric_key:{value:number|null,unit,status:ready|unavailable}}}`. Count=0 — измеренное отсутствие, null — нет источника/не настроено. UI не подменяет unavailable нулём. Основные keys: seller.dialogues, seller.handoffs_pending, vision.measurements, vision.latest_visibility, ai.calls, ai.tokens. Суммы/лимиты не смешиваются с display numbers. Домены экспортируют read DTO, Shell не делает cross-domain SQL.

## Concurrency, доставка и события

`TurnCommand` содержит `{conversationId,inputMessageId,text,externalEventId,idempotencyKey,expectedVersion?}`; tenant берётся отдельно. Вход сохраняется один раз. Уникальность внешнего события `(channel_account_id, external_id)`; API `(brand_id, endpoint, idempotency_key)` + SHA-256 нормализованного body. Равный key/body возвращает прежний результат или состояние in_progress; другой body → 409. Хранить status/outcome, не только cache lock.

Claim/finalize — короткие DB transactions; LLM/HTTP выполняются вне блокировки SQL. Только один активный turn на conversation. Commit проверяет token/version и atomically сохраняет state/messages/tasks/outbox. Повтор worker не выполняет tool side effect или outgoing delivery заново. При устаревшем claim ничего не доставлять. Нельзя полагаться только на Redis lock с TTL как доказательство exactly-once.

TurnOutcome: `{turnId,conversationId,status:completed|suggested|suppressed|failed,version,assistantMessageId?,goal,guard,toolCalls}`. Goal/Guard сохраняют original meta. Suggest пишет предложение без Delivery; manager и paused не вызывают LLM/delivery, но сохраняют вход и применимый attention/task. DeliveryCommand содержит persisted message ID; Receipt `{status:delivered|retryable_failure|permanent_failure,externalId?,errorCode?}`. External provider может не поддерживать идемпотентность: after-send crash помечать uncertain и reconciliation, не обещать математическое exactly-once.

Event envelope:

```json
{"event_id":"01K8F000000000000000000001","schema_version":1,"type":"seller.conversation.handed_off","occurred_at":"2026-10-31T10:00:00Z","organization_id":"01K8F000000000000000000002","brand_id":"01K8F000000000000000000003","actor_id":null,"aggregate":{"type":"conversation","id":"01K8F000000000000000000004","version":4},"data":{"reason":"want_human","task_id":"01K8F000000000000000000005"}}
```

Payload содержит IDs/минимальные значения, не тексты диалогов и не секреты. Event producers перечислены в registry; E0-04 владеет transport/outbox/notification event catalog. Consumers идемпотентны по `(consumer,event_id)`; processing after commit, rollback не отправляет уведомление. Регистрация subscriber не добавляет новый тип события самовольно.

## Runtime extension points без изменения Engine чужим feature task

E3-03B определяет ports в `App\Seller\Runtime\Contracts`, null/default implementations и фиксированный порядок применения. Feature registry выбирает единственный adapter на key. BrandRuntime immutable на один turn: tenant, release/checksum, settings version, AiGateway, ports. Активный turn не начинает читать новый релиз после публикации посреди LLM-ответа.

| Key / feature owner | Signature / default |
|---|---|
| seller.greeting / E6-02 | `GreetingResolver::resolve(BrandRuntime, OpenContext): ?string`; null → исходная генерация intro |
| seller.catalog / E6-08 | `CatalogContext::forTurn(BrandRuntime, Goal): CatalogTurnContext`; disabled → пусто, никаких новых цен/чисел в prompt |
| seller.guard_severity / E4-05 | `GuardSeverity::forRule(BrandRuntime, string $rule): block|warn`; default block для всех original rules |
| seller.tool_handlers / E6-04, E6-08, E5-07 | keyed `ToolHandler::execute(ToolCommand): ToolResult`, каждое tool name с единственным владельцем; stock/catalog и mail adapters подключаются через делегирование core tool, не замену Policy |
| seller.schedule / E6-06 | `Availability::forTurn(BrandRuntime, TurnContext): AvailabilityDecision`; default available без нового текста |
| seller.assignment / E6-06 | `AssignmentResolver::forHandoff(BrandRuntime, HandoffContext): ?UserId`; default null |
| export.<module> / доменный владелец | `ExportContributor` из E1-11; один adapter per module, не общий switch по таблицам внутри Shell |

CatalogTurnContext: structured selected items + `allowedNumericFacts`, provenance/checksum. Responder получает текст только при catalog_prices_enabled=true; Guard получает те же разрешённые конкретные числа, не глобальное отключение numeric checks. Severity обрабатывается после неизменной детекции Guard: warn остаётся в meta.guard.warnings, только block запускает регенерацию. Fallback/opt-out/prompt injection safety поведение не отключается неизвестным extension key. Runtime должен тестировать disabled/default adapters против исходного поведения.

Persona/conditions/triggers остаются частью snapshot, а служебные phrases/followup — типизированными settings в BrandRuntime. Feature adapters не произвольно переписывают system prompt. Export hooks добавляются, когда появится порт E1-11; E9-01 проверяет регистрацию всех реализованных модулей.
