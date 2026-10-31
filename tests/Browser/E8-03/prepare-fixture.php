<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\Profiles\Questionnaire;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Explicit opt-in and actual connection checks precede any database write.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('E803_FIXTURE_ENABLED') !== '1') {
    throw new LogicException('Fixture requires an explicitly enabled isolated testing process.');
}
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
$root = str_replace('\\', '/', realpath(__DIR__.'/../../..'));
$storage = str_replace('\\', '/', storage_path());
$state = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
if (! app()->environment('testing') || str_replace('\\', '/', base_path()) !== $root || DB::connection()->getDatabaseName() !== 'souzpir_test_sp_e8_03'
    || config('runtime.test_database') !== 'souzpir_test_sp_e8_03' || config('runtime.preview_database') === 'souzpir_test_sp_e8_03'
    || ! $state || $state->db !== 'souzpir_test_sp_e8_03' || $state->role !== 'sp_e8_03_app'
    || $state->host !== '127.0.0.1' || (int) $state->port !== 55500 || $state->rolsuper || $state->rolcreatedb || $state->rolcreaterole
    || ! str_starts_with($storage.'/', $root.'/.local/runtime/testing/storage/')
    || config('runtime.ai_driver') !== 'fake' || config('runtime.delivery_driver') !== 'fake'
    || ! in_array(config('mail.default'), ['log', 'array'], true)) {
    throw new LogicException('Refusing fixture outside isolated E8-03 database, storage and fake adapters.');
}

// Never delete or replace a pre-existing tenant. Each new run requires a fresh isolated test DB.
[$owner, $membership, $brands] = DB::transaction(function (): array {
    $orgId = app(Cabinet::class)->organizationId();
    if (DB::table('organizations')->exists() || DB::table('users')->exists()) {
        throw new LogicException('Fixture requires a fresh isolated database; existing data is preserved.');
    }
    $owner = User::create(['name' => 'Владелец теста вопросов', 'email' => 'vision-e803@example.test', 'password' => 'test-only-password-123']);
    $editor = User::create(['name' => 'Редактор теста вопросов', 'email' => 'vision-e803-editor@example.test', 'password' => 'test-only-password-123']);
    $viewer = User::create(['name' => 'Наблюдатель теста вопросов', 'email' => 'vision-e803-viewer@example.test', 'password' => 'test-only-password-123']);
    foreach ([$owner, $editor, $viewer] as $user) {
        $user->forceFill(['email_verified_at' => now(), 'auth_version' => 0])->save();
    }
    $org = new Organization;
    $org->forceFill(['id' => $orgId, 'name' => 'Тестовая организация вопросов', 'status' => 'active', 'owner_user_id' => $owner->id,
        'settings' => ['test_fixture' => 'SP-E8-03-browser-fixture-v1']])->save();
    [$membership, $brands] = app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id, source: 'job'), function () use ($owner, $editor, $viewer, $orgId): array {
        $first = Brand::create(['name' => 'Альфа · тест вопросов', 'website' => 'https://alpha.example.com', 'theme' => 'neutral', 'timezone' => 'Europe/Moscow']);
        $other = Brand::create(['name' => 'Зета · другой бренд', 'website' => 'https://beta.example.com', 'theme' => 'pyro', 'timezone' => 'Europe/Moscow']);
        $ownerMembership = Membership::create(['user_id' => $owner->id, 'role' => 'owner', 'brand_ids' => [$first->id, $other->id]]);
        Membership::create(['user_id' => $editor->id, 'role' => 'member', 'brand_ids' => [$first->id], 'settings' => ['sales_manager' => false]]);
        Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$first->id]]);
        foreach ([$first, $other] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $orgId, 'brand_id' => $brand->id,
                'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$ownerMembership, [$first, $other]];
    });

    return [$owner, $membership, $brands];
});

// Create two actual submitted profiles through the owner/session authorization and E8-01F service.
// Each uses only synthetic examples; no scheduler, external AI, mail, webhook or HTTP request.
Auth::login($owner);
session()->start();
session()->put('auth.version', $owner->auth_version);
$request = Request::create('/vision/quiz', 'POST');
$request->setLaravelSession(app('session.store'));
$request->setUserResolver(fn () => $owner);
$app->instance('request', $request);
app(AuthSessionRegistry::class)->issue($request, $owner, $owner->auth_version);
foreach ($brands as $index => $brand) {
    session()->put('brand_id', $brand->id);
    $context = new TenantContext($brand->organization_id, $brand->id, $owner->id, $membership->id);
    app(TenantContextRunner::class)->run($context, function () use ($request, $index): void {
        $quiz = app(Questionnaire::class);
        $draft = $quiz->start($request);
        $name = $index === 0 ? 'Альфа Тест' : 'Зета Тест';
        $domain = $index === 0 ? 'alpha.example.com' : 'beta.example.com';
        $sections = [
            1 => ['brand_name' => $name, 'site' => 'https://'.$domain],
            2 => ['aliases' => [$name, $domain]],
            3 => ['category' => 'кондитерские изделия', 'categories' => ['кондитерские изделия'], 'offering' => 'goods',
                'audience' => 'b2c', 'buyer_phrase' => 'торты на заказ', 'category_forms' => ['nominative' => 'торты', 'genitive_plural' => 'тортов']],
            4 => ['competitors' => []], 5 => ['facts' => []], 6 => ['presence' => []], 7 => ['questions' => []],
        ];
        $revision = $draft['revision'];
        foreach ($sections as $step => $values) {
            $revision = $quiz->saveStep($request, $draft['id'], $revision, $step, $values);
        }
        $quiz->submit($request, $draft['id'], $revision);
    });
}
Http::assertNothingSent();
fwrite(STDOUT, "Isolated synthetic E8-03 browser fixture ready; two real service-created profiles, no external requests.\n");
