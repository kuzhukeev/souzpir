<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getDatabaseName();
$orgId = getenv('E112_FIXTURE_ORG');
if (getenv('E112_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || $db !== config('runtime.test_database') || $db === config('runtime.preview_database') || ! str_contains($db, '_test_') || ! is_string($orgId) || ! Str::isUlid($orgId) || $orgId !== app(Cabinet::class)->organizationId()) {
    throw new LogicException('Requires an explicitly enabled isolated testing database and fixture organization.');
}
Artisan::call('migrate', ['--force' => true]);
$manifest = DB::transaction(function () use ($orgId): array {
    $marker = 'SP-E1-12-browser-fixture-v1';
    $users = [];
    foreach (['owner' => 'Анна Лебедева', 'viewer' => 'Павел Новиков', 'editor' => 'Михаил Соколов'] as $role => $name) {
        $email = 'support-e112-'.$role.'@example.test';
        $user = User::where('email', $email)->lockForUpdate()->first();
        if ($user && ($user->name !== $name || DB::table('memberships')->where('user_id', $user->id)->where('organization_id', '!=', $orgId)->exists())) {
            throw new LogicException('Fixture user collision.');
        }
        $user ??= User::create(['name' => $name, 'email' => $email, 'password' => 'test-only-password-123']);
        $user->forceFill(['email_verified_at' => now(), 'terms_accepted_at' => now(), 'ai_consent_at' => now()])->save();
        $users[$role] = $user;
    }
    $org = Organization::whereKey($orgId)->lockForUpdate()->first();
    if ($org && (($org->settings['test_fixture'] ?? null) !== $marker || $org->owner_user_id !== $users['owner']->id)) {
        throw new LogicException('Fixture organization collision.');
    }
    if (! $org) {
        $org = new Organization;
        $org->forceFill(['id' => $orgId, 'name' => 'Союз Пир — помощь', 'settings' => ['test_fixture' => $marker]])->save();
    }
    $brands = [];
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $users['owner']->id, source: 'job'), function () use ($orgId, $users, &$brands): void {
        foreach (['neutral' => 'Союз Пир — основной бренд', 'pyro' => 'Праздничные огни и профессиональная пиротехника', 'confectionery' => 'Кондитерская «Малиновый сад»'] as $theme => $name) {
            $brand = Brand::withoutGlobalScopes()->where('organization_id', $orgId)->where('name', $name)->first();
            if ($brand && ($brand->settings['test_fixture'] ?? null) !== 'SP-E1-12-browser-fixture-v1') {
                throw new LogicException('Fixture brand collision.');
            }
            $brand ??= Brand::create(['name' => $name, 'theme' => $theme, 'status' => 'active', 'settings' => ['test_fixture' => 'SP-E1-12-browser-fixture-v1']]);
            $brands[$theme] = ['id' => $brand->id, 'name' => $brand->name];
        }
        foreach ($users as $role => $user) {
            $member = Membership::withoutGlobalScopes()->where('organization_id', $orgId)->where('user_id', $user->id)->first();
            if (! $member) {
                Membership::create(['user_id' => $user->id, 'role' => $role === 'editor' ? 'member' : $role, 'brand_ids' => $role === 'owner' ? [] : array_column($brands, 'id')]);
            }
        }
    });
    $org->forceFill(['owner_user_id' => $users['owner']->id])->save();

    return ['organization_id' => $orgId, 'brands' => $brands, 'owner_email' => $users['owner']->email, 'viewer_email' => $users['viewer']->email, 'editor_email' => $users['editor']->email];
});
file_put_contents(base_path('.local/runtime/e1-12-browser.json'), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
fwrite(STDOUT, "Isolated support fixture ready; existing tickets and organization preserved.\n");
