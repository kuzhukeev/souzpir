<?php

namespace Tests\Feature\Shell\Notifications;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AfterCommitTest extends TestCase
{
    use DatabaseMigrations;

    public function test_delivery_is_after_real_commit_and_rollback_never_dispatches(): void
    {
        $user = User::create(['name' => 'Owner', 'email' => 'notify@example.test', 'password' => 'example-password']);
        $organization = Organization::create(['name' => 'Cabinet', 'owner_user_id' => $user->id]);
        [$brand, $member] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($user): array {
            return [Brand::create(['name' => 'Brand']), Membership::create(['user_id' => $user->id, 'role' => 'owner'])];
        });
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $make = fn () => new DomainEvent([
            'event_id' => (string) Str::ulid(), 'schema_version' => 1,
            'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $organization->id, 'brand_id' => $brand->id, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1],
            'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()],
        ]);
        DB::transaction(function () use ($make): void {
            app(NotificationDispatcher::class)->enqueue($make());
            $this->assertDatabaseCount('outbox_events', 1);
            $this->assertDatabaseCount('cabinet_notifications', 0);
        });
        $this->assertDatabaseCount('cabinet_notifications', 1);
        try {
            DB::transaction(function () use ($make): void {
                app(NotificationDispatcher::class)->enqueue($make());
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('cabinet_notifications', 1);
    }
}
