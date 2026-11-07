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
if (getenv('RUN_ACCEPTANCE_E901G') !== '1' || ! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'souzpir_test_e901g'
    || DB::connection()->getConfig('username') !== 'e901g_app' || config('runtime.test_database') !== 'souzpir_test_e901g'
    || config('runtime.preview_database') === 'souzpir_test_e901g' || ! is_string($organization) || ! Str::isUlid($organization)
    || app(Cabinet::class)->organizationId() !== $organization) {
    throw new LogicException('Dedicated acceptance environment required.');
}
if (getenv('E901_ACTION') === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    if (Organization::whereKey($organization)->exists()) {
        throw new LogicException('Acceptance organization already exists.');
    }
    $org = new Organization;
    $org->forceFill(['id' => $organization, 'name' => 'Acceptance fixture', 'settings' => ['test_fixture' => 'SP-E9-01G']])->save();
    echo 'Ready';
} elseif (getenv('E901_ACTION') === 'seed-unknown') {
    $org = Organization::whereKey($organization)->firstOrFail();
    if (($org->settings['test_fixture'] ?? null) !== 'SP-E9-01G') {
        throw new LogicException('Unexpected organization.');
    }
    $brand = DB::table('brands')->where('organization_id', $organization)->where('name', 'Тестовый бренд')->value('id');
    $contact = DB::table('contacts')->where('organization_id', $organization)->where('brand_id', $brand)->where('email', 'widget@example.test')->value('id');
    $dialog = DB::table('conversations')->where('organization_id', $organization)->where('brand_id', $brand)->where('contact_id', $contact)->value('id');
    if (! is_string($brand) || ! is_string($dialog)) {
        throw new LogicException('Synthetic dialog missing.');
    }
    DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand, 'conversation_id' => $dialog, 'type' => 'unknown_question', 'payload' => json_encode(['question' => 'Когда доставка?'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
    echo 'Ready';
} elseif (getenv('E901_ACTION') === 'seed-second') {
    $org = Organization::whereKey($organization)->firstOrFail();
    if (($org->settings['test_fixture'] ?? null) !== 'SP-E9-01G') {
        throw new LogicException('Unexpected organization.');
    }
    $second = (string) Str::ulid();
    DB::table('brands')->insert(['id' => $second, 'organization_id' => $organization, 'name' => 'Второй тестовый бренд', 'theme' => 'neutral', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $second, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    echo $second;
} elseif (getenv('E901_ACTION') === 'brand-id') {
    $brand = DB::table('brands')->where('organization_id', $organization)->where('name', 'Тестовый бренд')->value('id');
    if (! is_string($brand)) {
        throw new LogicException('Onboarding brand missing.');
    }
    echo $brand;
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
