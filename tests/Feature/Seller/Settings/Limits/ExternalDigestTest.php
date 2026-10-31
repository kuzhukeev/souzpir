<?php

namespace Tests\Feature\Seller\Settings\Limits;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Settings\Limits\DigestService;
use App\Seller\Settings\Limits\DigestSlotLock;
use App\Seller\Settings\Limits\DigestTransport;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExternalDigestTest extends TestCase
{
    use DatabaseMigrations;

    private function pending(): string
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'digest@example.test', 'password' => 'test-strong-password']);
        $org = Organization::create(['name' => 'Digest', 'owner_user_id' => $owner->id]);
        [$brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), function () use ($owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);

            return [Brand::create(['name' => 'Digest brand', 'timezone' => 'Europe/Moscow'])];
        });
        $type = 'seller.conversation.handed_off';
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => $type, 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::table('outbox_events')->insert(['id' => $event->envelope['event_id'], 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_key' => $type, 'recipient' => 'owner', 'cabinet' => true, 'email' => true, 'telegram' => false, 'timing' => 'digest', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => $type, 'channel' => 'email', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $slot = ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'channel' => 'email', 'due_at' => now()->subMinute()->format('Y-m-d H:i:sP')];
        $batch = (string) Str::ulid();
        DB::table('seller_digest_batches')->insert([...$slot, 'id' => $batch, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_digest_items')->insert([...$slot, 'id' => (string) Str::ulid(), 'batch_id' => $batch, 'event_id' => $event->envelope['event_id'], 'created_at' => now(), 'updated_at' => now()]);
        config(['features.E0-04.defaults.external_delivery' => true, 'mail.default' => 'smtp']);

        return $batch;
    }

    public function test_uncertain_external_delivery_is_never_automatically_retried(): void
    {
        $id = $this->pending();
        $fake = new class extends DigestTransport
        {
            public int $calls = 0;

            public function send(string $channel, string $destination, array $titles): void
            {
                $this->calls++;
                throw new \RuntimeException('Result unknown');
            }
        };
        $this->app->instance(DigestTransport::class, $fake);
        $service = app(DigestService::class);
        $service->deliver($id);
        $service->deliver($id);
        self::assertSame(1, $fake->calls);
        $this->assertDatabaseHas('seller_digest_batches', ['id' => $id, 'status' => 'uncertain', 'last_error' => 'delivery_uncertain']);
    }

    public function test_confirmed_fake_transport_sends_once(): void
    {
        $id = $this->pending();
        $fake = new class extends DigestTransport
        {
            public int $calls = 0;

            public function send(string $channel, string $destination, array $titles): void
            {
                $this->calls++;
            }
        };
        $this->app->instance(DigestTransport::class, $fake);
        app(DigestService::class)->deliver($id);
        app(DigestService::class)->deliver($id);
        self::assertSame(1, $fake->calls);
        $this->assertDatabaseHas('seller_digest_batches', ['id' => $id, 'status' => 'sent']);
    }

    public function test_parallel_workers_claim_a_batch_only_once(): void
    {
        $id = $this->pending();
        $base = base_path('.local/tmp/digest-race-'.Str::random(12));
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $cmd = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/digest-worker.php', $id, $base, (string) $i];
                $env = array_merge(getenv(), ['APP_ENV' => 'testing']);
                $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 20;
            while ((! is_file($base.'.ready0') || ! is_file($base.'.ready1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($base.'.ready0');
            self::assertFileExists($base.'.ready1');
            file_put_contents($base.'.release', '1');
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $output.$error);
            }
            $workers = [];
            self::assertCount(1, file($base.'.calls', FILE_IGNORE_NEW_LINES));
            $this->assertDatabaseHas('seller_digest_batches', ['id' => $id, 'status' => 'sent']);
        } finally {
            file_put_contents($base.'.release', '1');
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
            foreach (['.ready0', '.ready1', '.release', '.calls'] as $suffix) {
                if (is_file($base.$suffix)) {
                    unlink($base.$suffix);
                }
            }
        }
    }

    public function test_late_event_cannot_disappear_when_another_process_closes_slot(): void
    {
        $id = $this->pending();
        $due = '2026-10-31 06:00:00+00';
        DB::table('seller_digest_batches')->where('id', $id)->update(['channel' => 'cabinet', 'due_at' => $due]);
        DB::table('seller_digest_items')->where('batch_id', $id)->update(['channel' => 'cabinet', 'due_at' => $due]);
        $batch = DB::table('seller_digest_batches')->where('id', $id)->first();
        $source = DB::table('outbox_events')->first();
        $payload = json_decode($source->envelope, true, 512, JSON_THROW_ON_ERROR);
        $eventId = (string) Str::ulid();
        $payload['event_id'] = $eventId;
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'envelope' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        Carbon::setTestNow('2026-10-31T06:01:00Z');
        $base = base_path('.local/tmp/digest-late-'.Str::random(12));
        $process = null;
        DB::beginTransaction();
        try {
            app(DigestSlotLock::class)->acquire($batch->organization_id, $batch->brand_id, $batch->user_id, 'cabinet', $due);
            $cmd = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/digest-route-worker.php', $eventId, $batch->user_id, $base];
            $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']));
            self::assertIsResource($process);
            $deadline = microtime(true) + 20;
            while (! is_file($base.'.route-ready') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($base.'.route-ready');
            usleep(200000);
            self::assertTrue(proc_get_status($process)['running']);
            app(DigestService::class)->deliver($id);
            DB::commit();
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output.$error);
            $process = null;
            $item = DB::table('seller_digest_items')->where('event_id', $eventId)->sole();
            self::assertNull($item->batch_id);
            self::assertSame('2026-11-01 06:00', CarbonImmutable::parse($item->due_at)->utc()->format('Y-m-d H:i'));
            $this->assertDatabaseHas('seller_digest_batches', ['id' => $id, 'status' => 'sent']);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($process !== null) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
            Carbon::setTestNow();
            if (is_file($base.'.route-ready')) {
                unlink($base.'.route-ready');
            }
        }
    }

    public function test_event_committed_before_closing_is_included_in_that_batch(): void
    {
        $id = $this->pending();
        $due = '2026-10-31 06:00:00+00';
        DB::table('seller_digest_batches')->where('id', $id)->update(['due_at' => $due]);
        DB::table('seller_digest_items')->where('batch_id', $id)->update(['due_at' => $due]);
        $batch = DB::table('seller_digest_batches')->where('id', $id)->first();
        $source = DB::table('outbox_events')->first();
        $payload = json_decode($source->envelope, true, 512, JSON_THROW_ON_ERROR);
        $eventId = (string) Str::ulid();
        $payload['event_id'] = $eventId;
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'envelope' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $event = new DomainEvent($payload);
        $member = Membership::withoutGlobalScopes()->where('organization_id', $batch->organization_id)->where('user_id', $batch->user_id)->firstOrFail();
        CarbonImmutable::setTestNow('2026-10-31T05:00:00Z');
        $base = base_path('.local/tmp/digest-included-'.Str::random(12));
        $process = null;
        DB::beginTransaction();
        try {
            app(DigestSlotLock::class)->acquire($batch->organization_id, $batch->brand_id, $batch->user_id, 'email', $due);
            $cmd = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/digest-worker.php', $id, $base, '0'];
            $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DIGEST_TEST_NOW' => '2026-10-31T06:01:00Z']);
            $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            self::assertIsResource($process);
            $deadline = microtime(true) + 20;
            while (! is_file($base.'.ready0') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($base.'.ready0');
            file_put_contents($base.'.release', '1');
            usleep(200000);
            self::assertTrue(proc_get_status($process)['running']);
            self::assertSame('digest', app(SellerNotificationRouter::class)->route($event, $member, 'email'));
            DB::commit();
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output.$error);
            $process = null;
            $this->assertDatabaseHas('seller_digest_items', ['event_id' => $eventId, 'batch_id' => $id]);
            $calls = file($base.'.calls', FILE_IGNORE_NEW_LINES);
            self::assertCount(1, $calls);
            self::assertCount(2, json_decode($calls[0], true, 512, JSON_THROW_ON_ERROR));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            file_put_contents($base.'.release', '1');
            if ($process !== null) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
            CarbonImmutable::setTestNow();
            foreach (['.ready0', '.release', '.calls'] as $suffix) {
                if (is_file($base.$suffix)) {
                    unlink($base.$suffix);
                }
            }
        }
    }
}
