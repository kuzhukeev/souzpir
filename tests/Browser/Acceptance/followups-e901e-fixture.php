<?php

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$organization = getenv('E901_ORG');
if (getenv('RUN_ACCEPTANCE_E901E') !== '1' || ! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'souzpir_test_e901e'
    || DB::connection()->getConfig('username') !== 'souzpir_e901e' || config('runtime.test_database') !== 'souzpir_test_e901e'
    || config('runtime.preview_database') === 'souzpir_test_e901e' || ! is_string($organization) || ! Str::isUlid($organization)
    || app(Cabinet::class)->organizationId() !== $organization) {
    throw new LogicException('Dedicated acceptance environment required.');
}
if (getenv('E901_ACTION') === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    if (Organization::whereKey($organization)->exists()) {
        throw new LogicException('Acceptance organization already exists.');
    }
    $org = new Organization;
    $org->forceFill(['id' => $organization, 'name' => 'Acceptance fixture', 'settings' => ['test_fixture' => 'SP-E9-01E']])->save();
    echo 'Ready';
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
} elseif (getenv('E901_ACTION') === 'seed-agent-reply') {
    // The real bot-reply pipeline for inbound channels is not wired yet; the acceptance
    // flow seeds the single prior agent reply the real UI cannot yet produce, then drives
    // the actual follow-up command and settings through the browser.
    $dialog = getenv('E901_DIALOG');
    $at = getenv('E901_AT');
    if (! is_string($dialog) || ! Str::isUlid($dialog) || ! is_string($at) || $at === '') {
        throw new LogicException('Missing seed parameters.');
    }
    $conversation = DB::table('conversations')->where('organization_id', $organization)->where('id', $dialog)->first();
    if (! $conversation) {
        throw new LogicException('Conversation not found for this organization.');
    }
    DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $conversation->brand_id,
        'conversation_id' => $dialog, 'role' => 'agent',
        'text' => 'Исходный ответ бота.', 'meta' => '{}', 'created_at' => $at, 'updated_at' => $at]);
    echo 'Ready';
} elseif (getenv('E901_ACTION') === 'second-brand') {
    $brandId = (string) Str::ulid();
    DB::table('brands')->insert(['id' => $brandId, 'organization_id' => $organization, 'name' => 'Второй бренд', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brandId, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $channelId = (string) Str::ulid();
    DB::table('channel_accounts')->insert(['id' => $channelId, 'organization_id' => $organization, 'brand_id' => $brandId, 'type' => 'api', 'name' => 'API', 'status' => 'active', 'default_mode' => 'manager', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $contactId = (string) Str::ulid();
    DB::table('contacts')->insert(['id' => $contactId, 'organization_id' => $organization, 'brand_id' => $brandId, 'name' => 'Клиент второго бренда', 'created_at' => now(), 'updated_at' => now()]);
    $dialogId = (string) Str::ulid();
    DB::table('conversations')->insert(['id' => $dialogId, 'organization_id' => $organization, 'brand_id' => $brandId, 'channel_account_id' => $channelId, 'contact_id' => $contactId, 'mode' => 'manager', 'state' => '{}', 'last_client_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $eventId = (string) Str::ulid();
    DB::table('channel_inbound_events')->insert(['id' => $eventId, 'organization_id' => $organization, 'brand_id' => $brandId, 'channel_account_id' => $channelId, 'external_id' => $eventId, 'payload' => '{}', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('messages')->insert(['id' => (string) Str::ulid(), 'channel_account_id' => $channelId, 'external_event_id' => $eventId, 'organization_id' => $organization, 'brand_id' => $brandId,
        'conversation_id' => $dialogId, 'role' => 'client', 'text' => 'Здравствуйте от второго бренда.', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    echo $brandId;
} elseif (getenv('E901_ACTION') === 'advance-followups') {
    $at = getenv('E901_AT');
    if (! is_string($at) || $at === '') {
        throw new LogicException('Missing E901_AT.');
    }
    Carbon::setTestNow($at);
    try {
        Artisan::call('seller:prepare-followups');
    } finally {
        Carbon::setTestNow();
    }
    echo 'Ready';
} else {
    throw new LogicException('Unknown fixture action.');
}
