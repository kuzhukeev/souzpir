<?php

use App\Models\Brand;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== config('runtime.test_database') || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new LogicException('Requires isolated test database.');
}
$org = app(Cabinet::class)->organizationId();
$user = User::where('email', 'browser-auth@example.test')->where('name', 'Browser Fixture')->firstOrFail();
$brand = Brand::withoutGlobalScopes()->where('organization_id', $org)->where('name', 'Browser fixture')->firstOrFail();
$ctx = new TenantContext($org, $brand->id, $user->id, source: 'job');
DB::transaction(function () use ($ctx, $org, $brand, $user): void {
    DB::table('audit_logs')->where('organization_id', $org)->where('subject_type', 'browser_fixture')->where('subject_id', 'journal-browser-fixture')->delete();
    app(AuditWriter::class)->record($ctx, new AuditEntry('knowledge.fact.updated', 'browser_fixture', 'journal-browser-fixture', ['status' => 'черновик'], ['status' => 'готово']));
    $eventId = '01J00000000000000000000109';
    $deliveryId = '01J00000000000000000000209';
    if (! DB::table('outbox_events')->where('id', $eventId)->exists()) {
        $event = new DomainEvent(['event_id' => $eventId, 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => '01J00000000000000000000309', 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => '01J00000000000000000000409']]);
        app(NotificationDispatcher::class)->enqueue($event);
    }
    DB::table('notification_deliveries')->updateOrInsert(['id' => $deliveryId], ['organization_id' => $org, 'brand_id' => $brand->id, 'event_id' => $eventId, 'user_id' => $user->id, 'channel' => 'email', 'status' => 'failed', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
});
