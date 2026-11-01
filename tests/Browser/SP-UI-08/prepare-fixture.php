<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('SP_UI_08_FIXTURE') !== '1' || ! app()->environment('testing') || basename(base_path()) !== 'sp-ui-08'
    || DB::connection()->getDatabaseName() !== 'souzpir_test_sp_ui08b' || DB::connection()->getConfig('host') !== '127.0.0.1'
    || (string) DB::connection()->getConfig('port') !== '55991' || DB::connection()->getConfig('username') !== 'sp_ui08_app'
    || config('runtime.test_database') !== 'souzpir_test_sp_ui08b' || config('runtime.preview_database') === 'souzpir_test_sp_ui08b') {
    throw new LogicException('Требуется отдельная тестовая база SP-UI-08.');
}
Artisan::call('migrate', ['--force' => true]);
if (DB::table('users')->exists() || DB::table('organizations')->exists()) {
    throw new LogicException('Тестовая база должна быть пустой.');
}
$owner = User::create(['name' => 'Тестовый владелец', 'email' => 'owner-ui08@example.test', 'password' => 'test-only-password-123']);
$owner->forceFill(['email_verified_at' => now(), 'terms_accepted_at' => now(), 'ai_consent_at' => now()])->save();
$organization = new Organization;
$organization->forceFill(['id' => '01J00000000000000000000000', 'name' => 'Тестовая организация', 'owner_user_id' => $owner->id])->save();
app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id, source: 'job'), function () use ($owner, $organization): void {
    $brand = Brand::create(['name' => 'Тестовый бренд']);
    Membership::create(['user_id' => $owner->id, 'role' => 'owner', 'brand_ids' => []]);
    $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
    DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
    foreach (['email', 'telegram'] as $channel) {
        DB::table('notification_deliveries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'event_id' => $event->envelope['event_id'], 'user_id' => $owner->id, 'channel' => $channel, 'status' => 'failed', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
});
