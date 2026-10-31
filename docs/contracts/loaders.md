# Bootstrap и независимые features

Владелец исполняемого loader: SP-E0-01. Задача предоставляет один `FeatureRegistry` и smoke-тест, после чего feature-чаты не меняют bootstrap, root routes, lockfiles или общий seed runner.

## Детерминированное обнаружение

- Сканировать только непосредственные `config/features/*.php` и `routes/features/*.php`; идентификатор — имя файла без расширения, например `E3-03B`. Допустимый формат `^[A-Z][A-Z0-9]*-[0-9]{2}[A-Z]?$`.
- Сортировка имён побайтно; неизвестная/повторная регистрация одного ключа, route name, adapter key или permission key — ошибка загрузки, а не last-write-wins.
- Путь строится от `base_path`, не из запроса, env module path или БД. Feature config — обычные сериализуемые массивы; callbacks/объекты в config запрещены для совместимости с config cache.
- Отсутствие feature-файла означает, что implementation ещё не установлен. Production boot не создаёт фиктивные adapters. Если installed consumer требует отсутствующий contract binding — понятная boot error. Preview E0-01 может не подключать domain consumers вообще.
- Owner нового feature-файла — task с этим ID и его scope. Наследование task `E8-05B` не даёт права менять файл `E8-05.php`.

`config/features/E6-02.php` возвращает, например:

```php
return [
    'bindings' => [], // interface FQCN => implementation FQCN; unique
    'scoped_bindings' => [], // request/job scoped contracts; no overlap with bindings
    'middleware_aliases' => [], // alias => middleware FQCN; unique
    'shared_props' => [], // SharedPropsContributor FQCNs; unique
    'extensions' => [
        'seller.greeting' => App\Seller\Settings\Greetings\ConfiguredGreeting::class,
    ],
    'permissions' => [], // ability => PermissionEvaluator FQCN; unique
    'policies' => [], // model FQCN => policy FQCN; unique
    'listeners' => [], // event key => list of listener FQCN
    'commands' => [], // command FQCNs; registration, not execution
    'schedules' => [], // ScheduleContributor FQCNs; registration, not worker launch
    'seeders' => [], // explicit dev/testing seeders; never run on boot
    'defaults' => [], // accessible as config('features.E6-02.defaults...')
];
```

Permissions use `App\Providers\Features\PermissionEvaluator::allows(Authenticatable $user, string $ability, mixed ...$arguments): bool`; the registry validates classes and registers Laravel Gates. Model policies use `policies`. Duplicate abilities or models fail boot; cached configuration behaves identically.

Bootstrap provides `ScheduleContributor::register(Schedule $schedule): void` under its own provider namespace. Scheduler contributors require environment capability flags; no organisation purge contributor exists. Interface registration may be cached, but discovery must produce the same routes/config with and without Laravel caches.

Уточнение интегратора при стыковке E0-01/E0-02: `scoped_bindings` регистрирует `Container::scoped`, `middleware_aliases` — router aliases без правки bootstrap другими задачами. Повторный contract binding (включая конфликт normal/scoped) или alias вызывает ошибку. E0-02 подключает alias `tenant` и свой scoped context через собственный config.

`App\Providers\Features\SharedPropsContributor::share(Request $request): array` принадлежит E0-01. FeatureRegistry собирает contributors из `shared_props`; повторные top-level keys между contributors запрещены. `HandleInertiaRequests` объединяет минимальные базовые props и feature contributions только в обычной web Inertia middleware. Не регистрировать эти props через глобальный `Inertia::share` в service provider: публичные отчёты должны оставаться отдельной проекцией. Tenant-dependent значения возвращаются lazy closures: web Inertia middleware запускается раньше route middleware `tenant`. E0-02 отдаёт organization/brand/brands/theme; `can` регистрирует E0-03; до этого явный пустой набор существует только в PreviewProps. Preview с отсутствующим actor/context сохраняет null/empty props.

## Маршруты: один файл feature, явные группы

`routes/features/<ID>.php` возвращает массив group → registration closure; closure объявляет Laravel controller routes, не обрабатывает запрос. Loader выполняет closure внутри заранее заданной middleware/prefix group. Возвращённые closures не сохраняются в config. Route handlers — controller actions, поэтому route cache не зависит от serializing route closures.

```php
return [
    'web' => static function (): void {
        Route::get('/seller/channels', [ChannelController::class, 'index'])
            ->name('seller.channels.index');
    },
    'api' => static function (): void {
        Route::post('/leads', [LeadController::class, 'store'])
            ->name('api.v1.leads.store');
    },
];
```

| Group | Prefix / обязательная защита | Разрешённые владельцы |
|---|---|---|
| web | без prefix; web session, auth, verified, tenant resolver; CSRF на мутациях | UI feature owners |
| guest | без prefix; web session, guest/throttle по маршруту; CSRF | E1-01; E1-02 требует отдельного authenticated organization-only режима до выдачи задачи, см. integration-followups.md |
| api | `/api/v1`; api, Sanctum, token/session tenant resolver, throttle; ability отдельно на endpoint | E5-03 и согласованные API consumers |
| public | без prefix; throttle; без shared authenticated props | E5-02 widget, E8-05C public report |
| webhook | `/webhooks`; api, throttle, channel-specific signature/secret проверяется до dispatch | E5-01 Telegram; новые каналы после регистрации |
| dev | `/dev`; web + environment local/testing guard; в остальных средах 404 | F-02 |
| preview | `/preview`; web + environment local/testing guard, без auth/verified/tenant alias; в остальных средах 404 | P-01, изолированные первые макеты |

Auth verification/resend/logout и MFA endpoints, требующие session, объявляются через отдельную `auth` group: web+auth, без verified/tenant, owner E1-01. Нельзя требовать verified на самой проверке email. E1-01 дополнительно ограничивает guest/auth endpoints по своему назначению. Tenant alias появился только в E0-02; E0-01 регистрирует preview без потребителей отсутствующего alias.

Публичный отчёт не использует `web` group и не получает `auth`, brand chooser или internal report fields. На первой контрольной точке SSR bundle не требуется. До E8-05C интегратор выдаёт владельцу bootstrap отдельный scope для `resources/js/ssr.tsx` и общей SSR-конфигурации; E8-05C добавляет только свою страницу и data action. Нельзя незаметно создать второй frontend bundle или объявить обычный client render SSR-реализацией. Internal JSON для живых страниц может использовать web group с Accept JSON, чтобы не создавать второй bearer API поверх каждого Inertia Action.

Первый E0-01 preview использует явный `PreviewProps` с null auth/organization/brand, пустыми brands/can, modules=false и unread=0; эти значения допустимы только local/testing на preview/dev routes. Это не no-op authorization для production web group. E0-02/E0-03/E0-08 внедряют настоящие resolver/policies/shared props позже. Preview не зависит от отсутствующих domain aliases и не имитирует авторизованного пользователя.

## Миграции, переводы, seeds, типы

- Domain migrations: `database/migrations/<TASK-ID>/`. Bootstrap регистрирует только найденные подпапки с проверенным ID и одну явно разрешённую специальную подпапку `database/migrations/bootstrap/`. Первоначальные framework/users tables E0-01 создаёт именно в bootstrap/ согласно scope; сам migration root не становится edit target для task. Nested bootstrap/ также подключается loader.
- Имя миграции: `<YYYY_MM_DD_HHMMSS>_<task_id_lower>_<operation>.php`; anonymous migration class. Timestamp выдаёт автор после создания prerequisites, а task ID предотвращает одинаковый basename у разных чатов. Registry validator в E0-01 отвергает повторный basename. FK cycle `brands.active_knowledge_release_id` добавляется второй миграцией E3-01 после создания releases.
- Runtime migration order определяется timestamp, не порядком task ID. Интегратор проверяет clean PostgreSQL `migrate` для merged набора; dependency наличие нельзя заменять `Schema::hasTable` с тихим пропуском FK.
- Переводы production features: рекурсивно `lang/ru/<Domain>/<Feature>/*.php`, обычные массивы. Ключ `Domain/Feature/file.key`, например `Shell/Auth/login.title`. Loader экспортирует тот же namespace в frontend dictionary; одинаковые keys — ошибка. `ru.json` не общий edit target. Dictionary/shared prop внедряет E0-08; runtime `t(key)` helper — отдельный точный frontend scope через интегратора. **Изолированный первый preview P-01 может иметь inline русские подписи**, поскольку его scope не включает lang/ и E0-08 ещё не выполнена. Это не шаблон для production features.
- Page resolver: `resources/js/pages/**/*.tsx`, точное PascalCase имя от base path, например `Shell/Auth/Login`. В страницах screen IDs только трассировка; modal ID не требует собственной Inertia-page.
- Theme loading: в принадлежащем E0-01 `resources/js/app.tsx` использовать Vite eager glob `import.meta.glob('../css/themes/**/*.css', { eager: true })`. До F-01 пустой match допустим; не создавать placeholder в чужом `resources/css/themes/`. Новая тема из F-01 обнаруживается без правки app.tsx. Page glob остаётся lazy, иначе вся будущая UI попадёт в первый bundle.
- Feature PHP DTO находятся в принадлежащем feature namespace. Генератор E0-08 сканирует opt-in Data DTO marker/attribute; shared DTO в `app/Data/Shared`. Генерируемые файлы коммитит только выделенный владелец/интегратор, consumer их не редактирует. CI сравнивает generated output с Git.
- Seeder список — FQCN из feature config; explicit command выбирает dev/testing. Не обращаться к платным API, не активировать канал и не сидировать вымышленные тарифы.
- Все `tests/Unit`, `tests/Feature`, `tests/Browser` обнаруживаются тестовыми runners автоматически. Fixture source/hash и fake drivers не заменяются новым expected output без причины.

## Изоляция worktree

E0-01 фиксирует в `.env.example` следующие соглашения. Секреты и `.env` каждого worktree не коммитятся.

| Переменная | Пример / правило |
|---|---|
| APP_INSTANCE | `sp-e0-01`, уникальный slug; main preview имеет другой slug |
| APP_URL / VITE_DEV_PORT | назначенные интегратором уникальные адрес/порт, не вычислять свободность по номеру task |
| DB_CONNECTION / DB_DATABASE | pgsql / `souzpir_sp_e0_01`; отдельная БД на task и отдельная `..._test` для тестов |
| CACHE_PREFIX / REDIS_PREFIX | `${APP_INSTANCE}_cache_` / `${APP_INSTANCE}_redis_`; в фактическом .env подставить literal slug |
| SESSION_COOKIE | `${APP_INSTANCE}_session`; SESSION_DOMAIN пустой/точный host, не общий `.local` |
| APP_STORAGE_PATH | абсолютный `<worktree>/.local/runtime/storage`; bootstrap создаёт минимальные подкаталоги внутри проверенного worktree |
| APP_CONFIG_CACHE / APP_ROUTES_CACHE / APP_EVENTS_CACHE / VIEW_COMPILED_PATH | отдельные абсолютные файлы/папка внутри `.local/runtime`; production defaults допускаются только без общего worktree storage |
| QUEUE_CONNECTION | sync для первой preview; redis/database worker запускается отдельно и явно |
| QUEUE_NAMESPACE | `${APP_INSTANCE}`; фактические имена `${namespace}:seller-turns`, `:vision`, `:ai`, `:notifications`, `:default` |
| AI_DRIVER / DELIVERY_DRIVER | fake / fake по умолчанию локально и в tests |
| MAIL_MAILER | log или изолированный Mailpit; реальная отправка выключена |

`APP_STORAGE_PATH`, `QUEUE_NAMESPACE`, `DELIVERY_DRIVER` — соглашения этого проекта; bootstrap обязан реализовать их применение, это не утверждение о встроенных env Laravel. Tests fail fast, если DB_DATABASE не содержит test slug или совпадает с preview DB. Никаких автоматических destructive reset базы по startup. Preview доступ через OSPanel public webroot; repository root и `.local` не публикуются HTTP.

## Приёмка E0-01 для этих соглашений

Smoke fixture feature подтверждает discovery маршрута, config, translation и nested migration; удаление fixture убирает регистрацию. С cache/без cache route list совпадает. Два feature с одинаковым route/extension key дают явную ошибку. Два worktree имеют разные DB/cache/cookie/storage/ports. В незапущенном домене нет фальшивых «работающих» endpoint. Сервисы fake; scheduler/queue worker не стартуют от установки зависимостей.

`session` route group uses the regular session/CSRF web middleware without guest, auth or tenant requirements; it is suitable for invitation landing pages shared by guests and authenticated users. The web middleware includes Laravel AuthenticateSession so password changes invalidate old authenticated sessions.
