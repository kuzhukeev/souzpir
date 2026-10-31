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

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getDatabaseName();
if (getenv('E102_RESET_TEST_DATABASE') !== '1' || ! app()->environment('testing') || $db !== config('runtime.test_database') || $db === config('runtime.preview_database') || ! str_contains($db, '_test_')) {
    throw new LogicException('Refusing to reset any database except the configured isolated test database.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
$organizationId = app(Cabinet::class)->organizationId();
$user = User::create(['name' => 'Владелец тестового кабинета', 'email' => 'onboarding-browser@example.test', 'password' => 'test-only-password-123']);
$user->forceFill(['email_verified_at' => now()])->save();
$org = new Organization;
$org->forceFill(['id' => $organizationId, 'name' => 'Тестовая компания'])->save();
app(TenantContextRunner::class)->run(new TenantContext($organizationId, actorId: $user->id, source: 'job'), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
$org->forceFill(['owner_user_id' => $user->id])->save();
$image = imagecreatetruecolor(16, 16);
imagefilledrectangle($image, 0, 0, 15, 15, imagecolorallocate($image, 16, 90, 55));
imagepng($image, base_path('.local/tmp/e1-logo.png'));
imagedestroy($image);
fwrite(STDOUT, "Isolated browser fixture ready.\n");
