<?php

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$organization = getenv('E901_ORG');
if (getenv('RUN_ACCEPTANCE_E901') !== '1' || ! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'souzpir_test_e901'
    || DB::connection()->getConfig('username') !== 'souzpir_e901' || config('runtime.test_database') !== 'souzpir_test_e901'
    || config('runtime.preview_database') === 'souzpir_test_e901' || ! is_string($organization) || ! Str::isUlid($organization)
    || app(Cabinet::class)->organizationId() !== $organization) {
    throw new LogicException('Dedicated acceptance environment required.');
}
if (getenv('E901_ACTION') === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    if (Organization::whereKey($organization)->exists()) {
        throw new LogicException('Acceptance organization already exists.');
    }
    $org = new Organization;
    $org->forceFill(['id' => $organization, 'name' => 'Acceptance fixture', 'settings' => ['test_fixture' => 'SP-E9-01']])->save();
    echo 'Ready';
} elseif (getenv('E901_ACTION') === 'seed-dialogs') {
    $org = Organization::whereKey($organization)->firstOrFail();
    if (($org->settings['test_fixture'] ?? null) !== 'SP-E9-01') {
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
