<?php

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
$orgId = getenv('E102_FIXTURE_ORG');
if (getenv('E102_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || $db !== config('runtime.test_database') || $db === config('runtime.preview_database') || ! str_contains($db, '_test_') || ! is_string($orgId) || ! Str::isUlid($orgId) || $orgId !== app(Cabinet::class)->organizationId()) {
    throw new LogicException('Refusing fixture outside explicitly enabled isolated testing environment.');
}
Artisan::call('migrate', ['--force' => true]);
DB::transaction(function () use ($orgId): void {
    $marker = 'SP-E1-02-browser-fixture-v1';
    $email = 'onboarding-e102-v1@example.test';
    $user = User::where('email', $email)->lockForUpdate()->first();
    if ($user && $user->name !== $marker) {
        throw new LogicException('Fixture user collision.');
    }
    $org = Organization::whereKey($orgId)->lockForUpdate()->first();
    if ($org) {
        if (($org->settings['test_fixture'] ?? null) !== $marker || ! $user || $org->owner_user_id !== $user->id) {
            throw new LogicException('Fixture organization collision.');
        }
        if (DB::table('memberships')->where('organization_id', $orgId)->where('user_id', '!=', $user->id)->exists() || DB::table('memberships')->where('user_id', $user->id)->where('organization_id', '!=', $orgId)->exists() || DB::table('audit_logs')->where('organization_id', $orgId)->whereNotNull('actor_id')->where('actor_id', '!=', $user->id)->exists()) {
            throw new LogicException('Fixture contains records not owned by its synthetic user.');
        }
        // FK constraints fail closed and roll the whole transaction back if any other feature owns data here.
        DB::table('audit_logs')->where('organization_id', $orgId)->delete();
        DB::table('onboarding_progress')->where('organization_id', $orgId)->delete();
        DB::table('brands')->where('organization_id', $orgId)->delete();
        DB::table('memberships')->where('organization_id', $orgId)->where('user_id', $user->id)->delete();
        DB::table('organizations')->where('id', $orgId)->delete();
    }
    $user ??= User::create(['name' => $marker, 'email' => $email, 'password' => 'test-only-password-123']);
    $user->forceFill(['password' => 'test-only-password-123', 'email_verified_at' => now()])->save();
    $org = new Organization;
    $org->forceFill(['id' => $orgId, 'name' => 'Тестовая компания', 'settings' => ['test_fixture' => $marker]])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $user->id, source: 'job'), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
    $org->forceFill(['owner_user_id' => $user->id])->save();
});
$image = imagecreatetruecolor(16, 16);
imagefilledrectangle($image, 0, 0, 15, 15, imagecolorallocate($image, 16, 90, 55));
imagepng($image, base_path('.local/tmp/e1-logo.png'));
imagedestroy($image);
fwrite(STDOUT, "Isolated synthetic browser fixture ready.\n");
