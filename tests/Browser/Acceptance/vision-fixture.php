<?php

use App\Ai\CanonicalJson;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$organization = getenv('E901_ORG');
if (getenv('RUN_ACCEPTANCE_E901B') !== '1' || ! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'souzpir_test_e901b'
    || DB::connection()->getConfig('username') !== 'souzpir_e901b' || config('runtime.test_database') !== 'souzpir_test_e901b'
    || config('runtime.preview_database') === 'souzpir_test_e901b' || ! is_string($organization) || ! Str::isUlid($organization)
    || app(Cabinet::class)->organizationId() !== $organization) {
    throw new LogicException('Dedicated acceptance environment required.');
}
if (getenv('E901_ACTION') === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    if (Organization::whereKey($organization)->exists()) {
        throw new LogicException('Acceptance organization already exists.');
    }
    $org = new Organization;
    $org->forceFill(['id' => $organization, 'name' => 'Acceptance fixture', 'settings' => ['test_fixture' => 'SP-E9-01B']])->save();
    echo 'Ready';
} elseif (getenv('E901_ACTION') === 'seed-dialogs') {
    $org = Organization::whereKey($organization)->firstOrFail();
    if (($org->settings['test_fixture'] ?? null) !== 'SP-E9-01B') {
        throw new LogicException('Unexpected organization.');
    }
    $first = DB::table('brands')->where('organization_id', $organization)->where('name', 'Тестовый бренд')->value('id');
    if (! is_string($first)) {
        throw new LogicException('Onboarding brand missing.');
    }
    $second = (string) Str::ulid();
    DB::table('brands')->insert(['id' => $second, 'organization_id' => $organization, 'name' => 'Второй тестовый бренд', 'theme' => 'neutral', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$first, 'Клиент первого бренда'], [$second, 'Клиент второго бренда']] as [$brand, $name]) {
        DB::table('brand_modules')->updateOrInsert(['organization_id' => $organization, 'brand_id' => $brand, 'module' => 'seller'], ['id' => (string) Str::ulid(), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $contact = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $organization, 'brand_id' => $brand, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand, 'contact_id' => $contact, 'state' => '{}', 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
    }
    echo json_encode(['first' => $first, 'second' => $second], JSON_THROW_ON_ERROR);
} elseif (getenv('E901_ACTION') === 'prepare-vision') {
    $brand = DB::table('brands')->where('organization_id', $organization)->where('name', 'Тестовый бренд')->first();
    $user = User::where('email', getenv('E901_EMAIL'))->firstOrFail();
    $membership = DB::table('memberships')->where('organization_id', $organization)->where('user_id', $user->id)->value('id');
    if (! $brand || ! $membership) {
        throw new LogicException('Acceptance tenant unavailable.');
    }
    $second = DB::table('brands')->where('organization_id', $organization)->where('name', 'Второй тестовый бренд')->value('id');
    if (! $second) {
        throw new LogicException('Second acceptance brand unavailable.');
    }
    DB::table('brand_modules')->updateOrInsert(['organization_id' => $organization, 'brand_id' => $second, 'module' => 'vision'], ['id' => (string) Str::ulid(), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $viewerEmail = getenv('E901_VIEWER_EMAIL');
    if (! is_string($viewerEmail) || ! str_ends_with($viewerEmail, '@example.test')) {
        throw new LogicException('Synthetic viewer required.');
    }
    $viewer = User::create(['name' => 'Тестовый наблюдатель', 'email' => $viewerEmail, 'password' => 'test-only-password-123']);
    $viewer->forceFill(['email_verified_at' => now()])->save();
    app(TenantContextRunner::class)->run(new TenantContext($organization, source: 'job'), fn () => Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$brand->id]]));
    $tenant = new TenantContext($organization, $brand->id, $user->id, $membership, source: 'job');
    app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $brand): void {
        $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Широкий ассортимент']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [], 'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
        $repository = app(DatabaseKnowledgeRepository::class);
        $draft = $repository->createDraft($tenant, $source);
        $release = $repository->publish($tenant, $draft['id'], 1);
        $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $release->releaseId())->value('f.logical_id');
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        app(GrantRegistry::class)->issue($tenant, $release->releaseId(), [$fact]);
    });
    $dir = base_path('.local/runtime/e901b-fixtures');
    if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
        throw new LogicException('Fixture directory unavailable.');
    }
    $body = ['messages' => [['role' => 'user', 'content' => 'Где найти фейерверки для праздника?']], 'model' => 'google/gemini-2.5-flash', 'temperature' => 0.7, 'max_tokens' => 1200];
    $answer = ['content' => 'Тестовый ответ бренда', 'toolCalls' => [], 'usage' => ['promptTokens' => 2, 'completionTokens' => 3], 'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => $answer], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    echo 'Ready';
} elseif (getenv('E901_ACTION') === 'verify-link') {
    $email = getenv('E901_EMAIL');
    if (! is_string($email) || ! str_ends_with($email, '@example.test')) {
        throw new LogicException('Synthetic account required.');
    }
    $user = User::where('email', $email)->firstOrFail();
    URL::forceRootUrl((string) getenv('APP_URL'));
    echo URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
} else {
    throw new LogicException('Unknown fixture action.');
}
