"""Build the reviewed queue from the immutable source backlog plus explicit overrides."""
from pathlib import Path
import json, re

ROOT = Path(__file__).resolve().parents[1]
source = (ROOT/'docs/sources/10_backlog.md').read_text(encoding='utf-8')
original = {}
screen_owner = {}
for line in source.splitlines():
    cells = [x.strip() for x in line.strip('|').split('|')]
    if re.match(r'^\| (?:E\d|F|T)-\d\d \|', line):
        key,title,goal = cells[:3]
        refs,deps,size,checks = cells[-5:-1]
        original[key] = dict(title=title,goal=goal,refs=refs,deps=deps,size=size,checks=checks)
    elif re.match(r'^\| [SVD]-\d', line):
        screen_owner[cells[0]] = cells[2]
tasks = {}
def add(key,title,goal,deps,scope,checks,source_ids=None,screens=None,resources=None,notes=''):
    ids = source_ids or ([key] if key in original else [])
    tasks[key] = dict(taskKey='SP-'+key,title='SP-'+key+' · '+title,goal=goal,
        dependsOn=['SP-'+x for x in deps],scope=scope,exclusiveResources=resources or [],
        acceptance=checks if isinstance(checks,list) else [checks],sourceIds=ids,
        screenIds=screens if screens is not None else [s for s,o in screen_owner.items() if o in ids and s!='S-0.7'],
        notes=notes)

add('C-01','Контракты и реестр владения до кода',
    'Зафиксировать публичные PHP/TS DTO и интерфейсы между Tenancy, Ai, KnowledgeSnapshot, SellerRuntime, Delivery, UsageReader, Audit, Notifications, Vision; маршруты, события, payload ошибок, idempotency и версии. Реестр таблиц/ID/FK, миграций и владельцев: одно имя kn_escalation, единый active-release pointer, единый источник текущего тарифа. Одна организация текущего кабинета, несколько брендов. Определить route/migration/config/i18n auto-discovery для изолированных feature-папок. Задать extension points для greetings, catalog/price, Guard severity и exporters: feature-настройки должны влиять на runtime через внедряемые adapters без правок чужого Engine. Не проектировать головную админку.',
    [],['docs/contracts/'],['Контракты содержат примеры payload и негативные случаи 403/404/409/422.', 'Каждая таблица, shared file и событие имеют одного владельца; нет circular runtime dependencies.'])

# Areas are the only production paths a feature worker may change. Shared models are
# explicit extra scopes below, so the queue serializes their writers.
areas = {
'E0-02':'Tenancy','E0-03':'Shell/Permissions','E0-04':'Shell/Notifications','E0-05':'Ai','E0-06':'Shell/Plans',
'E1-01':'Shell/Auth','E1-02':'Shell/Onboarding','E1-03':'Shell/Dashboard','E1-04':'Shell/Organizations','E1-05':'Shell/Brands','E1-06':'Shell/Team','E1-07':'Shell/Billing','E1-08':'Shell/Profile','E1-09':'Shell/Journal','E1-10':'Shell/Integrations','E1-11':'Shell/Data','E1-12':'Shell/Support',
'E3-01':'Seller/Knowledge','E3-02':'Seller/Engine','E3-03':'Seller/Engine','E3-04':'Seller/Conversations','E3-05':'Seller/Followups','E3-06':'Seller/Evaluation','E3-07':'Seller/Equivalence',
'E4-01':'Seller/Knowledge/Releases','E4-02':'Seller/Knowledge/Facts','E4-03':'Seller/Knowledge/Conditions','E4-04':'Seller/Knowledge/Topics','E4-05':'Seller/Knowledge/Prohibitions','E4-06':'Seller/Knowledge/Imports','E4-07':'Seller/Sandbox','E4-08':'Seller/Knowledge/Fewshot',
'E5-01':'Seller/Channels/Telegram','E5-02':'Seller/Channels/Widget','E5-03':'Seller/Channels/Api','E5-04':'Seller/Dialogs','E5-05':'Seller/Customers','E5-06':'Seller/Channels/Planned','E5-07':'Seller/Channels/Email','E5-08':'Seller/Customers/ImportExport',
'E6-01':'Seller/Settings/Persona','E6-02':'Seller/Settings/Greetings','E6-03':'Seller/Settings/Policy','E6-04':'Seller/Settings/Tools','E6-05':'Seller/Settings/Techniques','E6-06':'Seller/Settings/Schedule','E6-07':'Seller/Settings/Limits','E6-08':'Seller/Catalog','E6-09':'Seller/Channels/Crm',
'E7-01':'Seller/Quality','E7-02':'Seller/Scenarios','E7-03':'Seller/Calibration','E7-04':'Seller/Analytics','E7-05':'Seller/Dashboard',
'E8-01':'Vision/Core','E8-02':'Vision/Profile','E8-03':'Vision/Questions','E8-04':'Vision/Measurements','E8-05':'Vision/Reports','E8-06':'Vision/Site','E8-07':'Vision/Settings',
'E9-02':'Migration','E9-03':'Shadow'}
def domain_scope(key,area):
    return [f'app/{area}/',f'resources/js/pages/{area}/',f'resources/js/features/{area}/',
            f'resources/js/types/{area}/',f'lang/ru/{area}/',f'database/migrations/{key}/',
            f'database/factories/{area}/',f'database/seeders/{area}/',f'routes/features/{key}.php',
            f'config/features/{key}.php',f'tests/Feature/{area}/',f'tests/Unit/{area}/',
            f'tests/Browser/{key}/',f'docs/modules/{key}.md']
for key,row in original.items():
    if key=='E0-07':
        continue  # User explicitly excluded the future head administration.
    deps=re.findall(r'(?:E\d|F|T)-\d\d',row['deps'])
    scope=domain_scope(key,areas[key]) if key in areas else [f'docs/modules/{key}.md']
    add(key,row['title'],row['goal'],deps,scope,row['checks'])

def change(key,**values): tasks[key].update(values)
def deps(key,*keys): tasks[key]['dependsOn']=['SP-'+x for x in keys]
def scope(key,*paths): tasks[key]['scope']=list(paths)
def extra(key,*paths): tasks[key]['scope']+=list(paths)
deps('E0-01','C-01')
scope('E0-01','composer.json','composer.lock','package.json','package-lock.json','bootstrap/','app/Providers/','app/Models/User.php','app/Http/','artisan','public/','resources/views/','resources/js/app.tsx','resources/css/app.css','routes/web.php','routes/console.php','config/','database/migrations/bootstrap/','database/seeders/DatabaseSeeder.php','tests/Pest.php','tests/TestCase.php','tests/Feature/Smoke/','tests/Browser/Smoke/','.github/','infra/','vite.config.ts','tsconfig.json','eslint.config.js','.prettierrc','phpunit.xml','pint.json','playwright.config.ts','vitest.config.ts','.env.example','.husky/','.gitignore','index.html','.htaccess','docs/environment.md')
change('E0-01',goal='Создать новый Laravel 13 + React 19/TypeScript/Inertia 3, Vite 8, Tailwind 4 в корне этого репозитория. PHP 8.4.19, совместимый Node >=22.12; единые composer/npm lockfiles. Исходники только доноры. Реализовать agreed auto-discovery routes/features, domain migrations, feature config/translation/test helpers без правки общего файла каждой задачей; CI lint/types/tests/smoke, .env.example с fake внешними сервисами, отдельные DB/cache/queue/storage/ports для worktree. Подготовить инструкции OSPanel public webroot, PostgreSQL/Redis/Mailpit и локальную страницу Inertia. Сохранить подготовительные docs/planning/tools/scripts; не применять composer create-project поверх них с потерей файлов.')
deps('E0-02','E0-01'); extra('E0-02','app/Models/Organization.php','app/Models/Brand.php','app/Models/Membership.php','app/Models/User.php','app/Shell/Team/Models/Invitation.php')
change('E0-02',notes='Организация одна для текущего кабинета; resolver не доверяет organization_id из запроса. Brand isolation сохраняется. S-0.7, переключение организаций и удаление организации не реализуются. Фундамент также создает invitations table/model и контракт проверки/принятия токена (expiry, revoked, accepted, email binding), чтобы Auth S-0.5 не зависел циклически от будущего Team UI. Рассылка и управление приглашениями принадлежат E1-06; один владелец первоначальной schema — E0-02.')
extra('E0-03','config/permissions.php')
extra('E0-04','app/Shell/Audit/')
extra('E0-05','resources/views/prompts/','app/Contracts/Ai/')
deps('E0-08','E0-03'); scope('E0-08','app/Http/Middleware/HandleInertiaRequests.php','app/Data/Shared/','resources/js/types/generated/','resources/js/hooks/usePermissions.ts','scripts/generate-types/','tests/Feature/SharedProps/','docs/contracts/frontend.md')
deps('F-01','E0-01'); scope('F-01','resources/css/themes/','public/fonts/','tests/Unit/ThemeTokens/','docs/design/tokens.md')
scope('F-02','resources/js/components/ui/','resources/js/pages/Dev/','routes/features/F-02.php','tests/Browser/DesignSystem/','docs/design/components.md')
scope('F-03','resources/js/layouts/','resources/js/components/navigation/','config/navigation.php','lang/ru/navigation.php','tests/Browser/Layout/','docs/design/layout.md')
scope('F-04','resources/js/lib/http/','resources/js/lib/query/','resources/js/hooks/usePolling.ts','tests/Unit/FrontendRuntime/','docs/design/live-data.md')
deps('T-01')
scope('T-01','tests/fixtures/prototype/','tools/baseline/','docs/baseline/')
change('T-01',title='SP-T-01 · Эталон продавца: offline harness и сценарии',goal='Скопировать прототип в изолированную рабочую область, не менять .local/references. Подготовить recording/replay harness, manifest 29 персон + 12 явно перечисленных ручных кейсов, golden prompt snapshots и доступные deterministic fixtures. Не запускать платные LLM и не выдумывать записанные ответы. Отдельно отметить recorded, synthetic и missing; 100% эквивалентность допустима только на настоящем baseline. Команда оператора live capture — в SP-T-03.',acceptance=['31 исходный тест запускать на совместимом окружении; указать реальные результаты и блокеры.', 'Replay не обращается в сеть; manifest содержит все 41 case IDs и происхождение данных; секреты редактируются до сохранения.'])
deps('E3-01','E0-02','C-01')
deps('T-02','E3-01','E0-05'); scope('T-02','app/Seller/Knowledge/Import/','tests/Feature/KnowledgeEquivalence/','tests/fixtures/knowledge/','docs/baseline/knowledge.md')
deps('E3-04','E0-02','E0-03','C-01')
extra('E3-04','app/Seller/Channels/Core/','app/Seller/Settings/BrandSellerSettings.php')
change('E3-04',goal='Ранний data layer: conversations/messages/seller_tasks/message_reviews/contacts, а также базовые channel_accounts/channel_inbound_events и brand_seller_settings, enums и persistence interfaces, изоляция бренда, транзакции, идемпотентность. E3-04 — единственный владелец первоначальной schema этих таблиц; channel adapters и Settings UI используют опубликованные модели, не создают дубликаты таблиц. ChannelAccount находится в Seller/Channels/Core, BrandSellerSettings в Seller/Settings. Модели и FK нужны до движка и агрегатора подключений Shell. Не реализовывать полноценный Turn runtime; bot/suggest/manager будут интегрированы в SP-E3-03B.',acceptance=['Миграции up/down и FK на PostgreSQL, включая nullable channel/contact связи и уникальный inbound external_id в пределах канала; чужой бренд 404; repository tests; режимы сохранены без отправки сообщений.', 'BrandSellerSettings defaults не меняют поведение прототипа; channel settings/credentials определены контрактом, секреты не попадают в DTO.'])
deps('E3-02','T-02','E3-04','E0-05')
change('E3-03',goal='Перенести Responder, Guard (22 правила + llmCheck), ToolRunner и Summarizer без потери поведения. Использовать опубликованные контракты и snapshot; определить фолбэки и handoffOnError. Orchestrator интегрируется отдельной SP-E3-03B.',acceptance=['GuardTest и tool unit tests зелёные; snapshots prompt defaults совпадают; неполученные LLM-fixtures не выдаются за эталон.'])
add('E3-03B','Orchestrator, turn/open и режимы доставки','Интегрировать перенесённые части движка с persistence и AiGateway, runtime per brand, meta, tool-calls, retry/fallback. bot доставляет через Delivery contract; suggest сохраняет подсказку без отправки; manager молчит. Доставка каналов остается отдельным владельцам.', ['E3-03','E3-04','E0-04'],['app/Seller/Engine/','app/Seller/Runtime/','tests/Feature/SellerRuntime/'],['TurnTest/open/regeneration/fallback, tenant isolation, double-submit/idempotency и defaults-equivalence проходят.'],['E3-03'])
deps('E3-05','E3-03B'); deps('E3-06','E3-03B')
add('T-03','Запись и проверка полного golden baseline','Операторский этап: записать реальные ответы для missing кейсов T-01 только после предоставления рабочего доступа и бюджета, либо импортировать уже существующие записи. Нельзя подменять результат synthetic. До реального разрешения/доступа задача блокируется, остальные ветви продолжаются.', ['T-01','E0-05'],['tests/fixtures/prototype/','tools/baseline/','docs/baseline/'],['Все 41 кейс имеют provenance, seed/config/model/prompt versions; повторный replay детерминирован и offline.'],['T-01'],notes='Внешнее условие: реальные LLM-записи/доступ и бюджет. Никогда не печатать ключи.')
deps('E3-07','E3-05','E3-06','T-03'); scope('E3-07','tests/Feature/Equivalence/','docs/validation/equivalence.md')

# Every UI slice consumes actual shared props, layout, policies and audit services.
for key in list(tasks):
    if key.startswith(('E1-','E4-','E5-','E6-','E7-','E8-')) and key not in {'E8-01'}:
        for dep in ['F-03','E0-03','E0-04']:
            if 'SP-'+dep not in tasks[key]['dependsOn']: tasks[key]['dependsOn'].append('SP-'+dep)
deps('E1-01','E0-03','F-02','E0-04'); change('E1-01',title='SP-E1-01 · Доступ в кабинет: вход, регистрация, восстановление и MFA',goal='S-0.1–S-0.5: вход/регистрация пользователя текущей организации, email verification, password reset, MFA TOTP и rate limits. Приглашения делаются через контракт Team. Выбора/переключения нескольких организаций S-0.7 нет; регистрация не создает вторую организацию.',notes='Применить пользовательское уточнение single-organization; источник S-0.7 исключен.')
change('E1-02',goal='S-0.6a–d: заполнение профиля единственной текущей организации → бренд → три темы → модули → первый шаг. Не создавать произвольные дополнительные организации; повторный onboarding идемпотентен.')
change('E1-04',goal='S-2.1 название и настройки текущей организации, передача владения с проверкой прав; S-2.2 бренды создать/архивировать; S-2.4 модули включить/выключить по настоящим plan limits. Удаление организации исключено: нет кнопки, API, job, таймера или cascade purge.',acceptance=['Передача владельца атомарна; policy/brand CRUD/module tests; UI/API удаления организации отсутствуют.'])
change('E1-11',goal='S-7.4: согласия с датой, экспорт данных по модулю/периоду (архив + SHA-256), контракты хранения и скачивания. Не реализовывать удаление организации/компании или ее брендов каскадом, отложенный purge или головную админку. Персональное удаление аккаунта не должно уничтожать организацию; точный сценарий согласовать отдельно.',acceptance=['Экспорт проверен на fake данных, isolation, checksum и истечение файла; нет organization-delete route/UI/job.'])
extra('E1-01','app/Models/User.php')
extra('E1-08','app/Shell/Auth/'); extra('E1-05','app/Models/Brand.php')
deps('E1-03','E0-03','E0-04','F-03','E7-05','E8-07')
tasks['E1-03']['notes']='Главная читает реальные module KPI, UsageReader и attention read-models после Seller Dashboard и Vision Settings/Dynamics; статические числа и mock-only агрегатор не принимаются.'
deps('E1-06','E0-03','E0-04','F-03','E1-01')
deps('E1-11','E0-03','E0-04','F-03','E3-04','E8-01')
tasks['E1-10']['dependsOn'].append('SP-E3-04')
deps('E1-07','E0-06','E3-04','E8-01','E1-12','F-03','E0-04')
deps('E5-01','E3-03B','E0-04','F-03'); extra('E5-01','app/Seller/Channels/Core/')
for key in ['E5-02','E5-03','E5-04','E5-05']:
    tasks[key]['dependsOn'].append('SP-E3-03B')
extra('E5-02','resources/widget/','vite.widget.config.ts')
change('E5-04',goal='D-2.1/D-2.2: трехколоночная лента и отдельный диалог, 16 слотов, Guard/Goal/meta, перехват/возврат боту. D-2.3/2.4/2.5 отделены в SP-E5-04B.',screenIds=['D-2.1','D-2.2'])
add('E5-04B','Мои диалоги, суфлёр и пометки','D-2.3 мобильный сценарий менеджера; D-2.4 очередь подсказок с редактированием/отправкой; D-2.5 пометки по словарю А.6. Использовать единый Dialog view и API первой части.', ['E5-04','F-04'],domain_scope('E5-04B','Seller/Dialogs'),['Проверены manager/suggest, mobile 390, races на присвоение и отправку подсказки, polling и очистка brand cache.'],['E5-04'],['D-2.3','D-2.4','D-2.5'])
for key in ['E4-05','E4-07','E6-04','E6-08','E5-07']:
    tasks[key]['dependsOn'].append('SP-E3-03B')
deps('E6-06','E3-03B','E1-06','F-03','E0-04'); extra('E6-06','app/Seller/Runtime/')
deps('E6-05','E3-03B','F-03','E0-04')
extra('E6-04','app/Seller/Engine/Tools/')
extra('E6-03','app/Seller/Followups/','app/Seller/Engine/Policy.php')
deps('E4-01','T-02','F-03','E0-04','E3-04','E3-06')
tasks['E4-01']['notes']='Входящие D-4.2 читают реальные seller_tasks, message_reviews и supervisor evaluations; эти data/runtime prerequisites должны быть merged, а не имитированы фикстурным API.'
deps('E5-06','E3-04','E1-12','F-03','E0-04')
deps('E4-08','E4-01','E5-04B','F-03','E0-04')
deps('E7-05','E5-01','E5-04B','E7-04','F-03','E0-04')

deps('E8-01','E0-05','E0-06','C-01')
change('E8-01',goal='Перенести core Vision/Visibility: schema, MeasurementRunner, ReportPreparation, KnowledgeAtom и метрики через AiGateway. Размер вопросного набора управляется данными, не константой 20. SiteInspector отдельно SP-E8-01B; не копировать seller M3.',acceptance=['Snapshot расчетов и отчета на fake ответах, tenant isolation, исторические размеры наборов 12/20/30.'])
add('E8-01B','Ядро аудита сайта','Перенести SiteInspector и SiteKnowledgeImporter с сетевыми ограничениями/таймаутами, fixtures HTML и маппингом 16+4 проверок. До Q-03 сохранять группы и смысл кода, не менять scoring ради макета.', ['E0-05','C-01'],domain_scope('E8-01B','Vision/SiteAudit'),['HTML fixtures, HTTP fake, отсутствие сетевых вызовов в tests; критерии/веса/границы описаны.'],['E8-01'])
tasks['E8-02']['dependsOn'].append('SP-E8-01B')
tasks['E8-06']['dependsOn'].append('SP-E8-01B')
change('E8-05',title='SP-E8-05 · Внутренний отчёт Видения',goal='V-5.1–V-5.9: секции внутреннего отчета, единые metric DTO, ограничения доступа, состояния, snapshot расчетов. Публикация и TTL в SP-E8-05C, конкуренты/площадки в SP-E8-05B.',screenIds=[f'V-5.{x}' for x in range(1,10)],acceptance=['Метрики не меняются из-за верстки; fake report snapshot, UI 1440/1024/390 и пустые/заблокированные состояния.'])
add('E8-05B','Конкуренты и площадки','V-6.1–6.4: сравнение и дедупликация конкурентов; V-7.1–7.3: SOURCE_TYPES, источники и рекомендации площадок. Сохранить определения метрик.', ['E8-05','F-03'],domain_scope('E8-05B','Vision/Competitors')+domain_scope('E8-05B','Vision/Sources'),['Дедуп атомарен, метрики на fixtures; CRUD/isolation, empty/error/loading, visual.'],['E8-05'],[f'V-6.{i}' for i in range(1,5)]+[f'V-7.{i}' for i in range(1,4)])
add('E8-05C','Публичный отчёт, TTL и отзыв ссылки v1','V-5.10/V-5.P: публикация SSR read-only отчета, безопасный token, настраиваемый срок действия и отзыв. Пользователь явно включил TTL в v1; не считать его C/v2. Публичная ссылка не раскрывает внутренние сообщения/секреты или другие бренды.', ['E8-05','F-01'],domain_scope('E8-05C','Vision/Publications')+['resources/views/public-report/'],['Time travel: до/после expires_at и revoke; 410 после срока/отзыва; noindex/cache policy; desktop/mobile visual.'],['E8-05'],['V-5.10'])
tasks['E8-07']['dependsOn']+=['SP-E8-05B','SP-E8-05C']

# Cross-cutting verification is a barrier after all implementation slices, not vague 'all'.
deps('E9-01',*[k for k in tasks if k.startswith(('E1-','E4-','E5-','E6-','E7-','E8-'))], 'E3-07')
scope('E9-01','tests/Browser/Acceptance/','tests/visual/','docs/validation/acceptance.md')
deps('E9-02','E3-04','T-02','E8-01')
change('E9-02',notes='Только offline copies и dry-run; не переносить/не удалять live данные без отдельного запуска оператором. Идентификаторы по contracts registry.')
deps('E9-03','E3-07','E5-01','E9-01')
change('E9-03',notes='Операторский этап с отдельным разрешением на live shadow, данными и бюджетом. 2-недельное наблюдение нельзя подменять smoke тестом; реальная исходящая доставка выключена.')
deps('E9-04',*[k for k in tasks if k!='E9-04'])
scope('E9-04','README.md','docs/runbooks/','docs/validation/final.md')

# User-requested visual checkpoint; not a replacement for the production feature tasks.
add('P-01','Первые макеты для согласования',
    'Сверстать локальный предпросмотр S-0.1 (вход, ошибки, блокировка, MFA, мобильный вариант) и S-1.2 (оболочка кабинета, требует действия, пустое состояние) по всем пяти исходным изображениям папок 1 и 13. Применять темы SP-F-01. Дать переключение состояний, темы, поиск/фильтры демонстрационных событий, доступную мобильную навигацию. Страницы только local/testing, отдельный префикс /preview; вход не аутентифицирует и не сохраняет пароль. Явно обозначить предпросмотр/демонстрационные данные. Нефункционирующие будущие действия объяснять, не делать вид готовой бизнес-логики. Не реализовывать S-0.7/удаление организации.',
    ['E0-01','F-01'],
    ['resources/js/pages/Preview/','resources/js/features/Preview/','routes/features/P-01.php','tests/Feature/Preview/','tests/Browser/Preview/','docs/design/preview.md'],
    ['Оба макета открываются через Inertia; контрольные ширины 1440/1024/390 без потери формы и навигации.',
     'Все состояния из пяти оригиналов доступны для просмотра; поиск/фильтры, меню и переключатели работают на демонстрационных данных.',
     'Preview недоступен в production; не создаёт сессии входа и не отправляет внешние запросы; проверены build/types и доступность.',
     'Приложить честные результаты визуальной сверки и ссылки для просмотра. После результата остановка до команды пользователя.'],
    source_ids=[],screens=['S-0.1','S-1.2'],
    notes='Дополнительная карточка по прямому указанию пользователя 31.10.2026: остановиться после БД и первых макетов. Это визуальное согласование, не завершение SP-E1-01/SP-E1-03/SP-F-03. См. docs/checkpoints/first-preview.md.')

for key,t in tasks.items():
    t['dependsOn']=sorted(set(t['dependsOn']))
    t['scope']=sorted(set(t['scope']))
    t['sourceRefs']=['docs/preparation/decisions.md','docs/contracts/','docs/sources/10_backlog.md']
    if t['sourceIds']:
        t['sourceRefs'] += ['docs/sources/02_screens_matrix.md','docs/sources/02c_discrepancies.md','docs/sources/04c_settings_paths.md']
    t['promptPath']=f'docs/tasks/{t["taskKey"]}.md'
    t['scope'] += [f'docs/results/{t["taskKey"]}.md']

state={}
def wave(key):
    if state.get(key)==1: raise ValueError('Cycle: '+key)
    if state.get(key)==2: return tasks[key]['wave']
    state[key]=1
    tasks[key]['wave']=max([wave(d.removeprefix('SP-'))+1 for d in tasks[key]['dependsOn']],default=0)
    state[key]=2
    return tasks[key]['wave']
for key in tasks: wave(key)

materials=json.loads((ROOT/'planning/materials-manifest.json').read_text(encoding='utf-8'))
for key,t in tasks.items():
    sources='\n'.join(f'- {i}: {original[i]["refs"]}' for i in t['sourceIds'])
    images=[r['path'] for r in materials['images'] if r['relative'].split('/')[0].isdigit() and any(s in r['screenHints'] for s in t['screenIds'])]
    description=f'''Прочитать AGENTS.md и docs/preparation/decisions.md. Выполнять только после разрешенного старта проекта и атомарного claim. Отдельный git worktree, не основной checkout.

Цель: {t['goal']}

Зависимости (нужен done после merge): {', '.join(t['dependsOn']) or 'нет'}.
Область записи: {', '.join(t['scope'])}.
Экраны: {', '.join(t['screenIds']) or 'инфраструктура/контракты'}.
Источники: {', '.join(t['sourceIds']) or 'аудит и решения пользователя'}.
{sources}

Приёмка:
'''+''.join(f'- {c}\n' for c in t['acceptance'])+f'''
Дополнительно: {t['notes'] or 'Сохранять поведение донора; изменения только по принятым решениям.'}
Не изменять shared contracts/locks/чужие модели за границами scope. Если требуется расширение scope — передать интегратору точное изменение, не обходить ownership. Доменным задачам использовать loader и регистрацию, созданные фундаментом; новый общий dependency добавляет интегратор отдельно.
Проверить реальные UI → validation → Action → storage → runtime → policy → audit пути; фиктивный API не считается готовым. UI на русском, три темы, состояния loading/empty/error, 1440/1024/390 и доступность. C/D остаются честными ограничениями/«Скоро». Реальные LLM/mail/Telegram/webhook вызовы в тестах запрещены без отдельного разрешенного live этапа.
Результат: commit в ветке, docs/results/{t['taskKey']}.md с выполненным scope, тестами, ограничениями и screenshots; submit переводит только в review. Done ставит интегратор после merge и проверки ancestry. Не обновлять золотые эталоны ради зеленых тестов.
'''
    t['description']=description
    path=ROOT/t['promptPath'];path.parent.mkdir(parents=True,exist_ok=True)
    path.write_text('# '+t['title']+'\n\n'+description+'\n## Материалы\n\nИсходники только для чтения: C:/OSPanel/home/souzpir.local/.local/references/{eyon-pilot,laravel-prototype}.\nПолный индекс изображений: planning/materials-manifest.json; точный состав по docs/sources/02b_mockup_journal.md.\n\n'+''.join('- `'+p+'`\n' for p in images),encoding='utf-8')

for prompt_file in (ROOT/'docs/tasks').glob('SP-*.md'):
    prompt_file.write_text(prompt_file.read_text(encoding='utf-8').rstrip()+'\n',encoding='utf-8')

ordered=sorted(tasks.values(),key=lambda t:(t['wave'],t['taskKey']))
manifest={'project':'souzpir','planVersion':1,'executionEnabled':True,'tasks':ordered,
          'excludedSourceTasks':{'E0-07':'Головная админка и ее контракты вне текущего проектирования по уточнению пользователя.'},
          'excludedScreens':{'S-0.7':'Один кабинет одной организации; выбор нескольких организаций исключен.'}}
(ROOT/'planning/tasks.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
coverage={s: [t['taskKey'] for t in ordered if s in t['screenIds']] for s in screen_owner}
for s in manifest['excludedScreens']: coverage[s]=['excluded: '+manifest['excludedScreens'][s]]
missing={s:v for s,v in coverage.items() if not v}
if missing: raise ValueError('Uncovered screens '+str(missing))
(ROOT/'planning/screen-coverage.json').write_text(json.dumps(coverage,ensure_ascii=False,indent=2),encoding='utf-8')
lines=['# Очередь souzpir','',f'{len(ordered)} задач; запуск разрешён пользователем 31.10.2026. Зависимости разблокируются только после merge. Номер волны — минимальная глубина DAG, а не обещание одновременного запуска всех задач волны. Пересечения scope дополнительно сериализуются.','', '| Задача | Волна | Зависимости | Экраны |','|---|---:|---|---|']
for t in ordered: lines.append(f'| [{t["title"]}](../{t["promptPath"]}) | {t["wave"]} | {", ".join(t["dependsOn"]) or "—"} | {", ".join(t["screenIds"]) or "—"} |')
(ROOT/'planning/QUEUE.md').write_text('\n'.join(lines)+'\n',encoding='utf-8')
print(json.dumps({'tasks':len(ordered),'sourceTasks':len(original),'coveredScreens':len(coverage),'maxWave':max(t['wave'] for t in ordered),'roots':[t['taskKey'] for t in ordered if not t['dependsOn']]},ensure_ascii=False))
