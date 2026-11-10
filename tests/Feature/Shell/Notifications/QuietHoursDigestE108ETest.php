<?php

namespace Tests\Feature\Shell\Notifications;

use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\ExternalDelivery;
use App\Shell\Notifications\Jobs\DeliverNotification;
use App\Shell\Notifications\NotificationProcessor;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

/** SP-E1-08E: personal quiet hours and daily digest apply to the real (non-fake) email/Telegram delivery path. */
final class QuietHoursDigestE108ETest extends TenancyTestCase
{
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => function (Request $request) {
            $this->sent[] = $request['text'];

            return Http::response(['ok' => true], 200);
        }]);
        config(['features.E0-04.defaults.external_delivery' => true, 'features.E0-04.defaults.telegram_bot_token' => 'synthetic-token', 'mail.default' => 'smtp', 'runtime.notification_driver' => null]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $moscow): void
    {
        $time = CarbonImmutable::parse($moscow, 'Europe/Moscow')->utc();
        CarbonImmutable::setTestNow($time);
        Carbon::setTestNow($time);
    }

    private function prepare(): array
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brands')->where('id', $item->id)->update(['timezone' => 'Europe/Moscow']);
            $context = new TenantContext($org->id, $item->id, $owner->id, $membership->id);
            app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'telegram', true, '1001'));
        }

        return [$owner, $org, $brand, $other];
    }

    private function timing(object $org, object $brand, object $user, ?string $start, ?string $end, ?string $digest): void
    {
        DB::table('profile_notification_timing_drafts')->insert(['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $user->id, 'quiet_start' => $start, 'quiet_end' => $end, 'digest_time' => $digest, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function emit(object $org, object $brand): void
    {
        $event = new DomainEvent([
            'event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1],
            'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()],
        ]);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        foreach (DB::table('notification_deliveries')->where('event_id', $event->envelope['event_id'])->where('channel', 'telegram')->pluck('id') as $id) {
            (new DeliverNotification($id))->handle(app(ExternalDelivery::class));
        }
    }

    private function statuses(object $brand): array
    {
        return DB::table('notification_deliveries')->where('brand_id', $brand->id)->where('channel', 'telegram')->orderBy('created_at')->pluck('status')->all();
    }

    public function test_event_in_quiet_hours_is_deferred_until_they_end(): void
    {
        $this->at('2026-11-08 20:00');
        [$owner, $org, $brand] = $this->prepare();
        $this->timing($org, $brand, $owner, '22:00', '08:00', null);
        $this->at('2026-11-08 23:00');
        $this->emit($org, $brand);
        $this->assertSame(['deferred'], $this->statuses($brand));
        $this->at('2026-11-09 02:00');
        app(ExternalDelivery::class)->drain(['pending']);
        $this->assertSame(['deferred'], $this->statuses($brand));
        $this->assertSame([], $this->sent);
        $this->at('2026-11-09 08:05');
        app(ExternalDelivery::class)->drain(['pending']);
        $this->assertSame(['sent'], $this->statuses($brand));
        $this->assertSame(['Диалог ждёт менеджера'], $this->sent);
    }

    public function test_daily_digest_groups_events_per_brand_and_channel(): void
    {
        $this->at('2026-11-08 08:00');
        [$owner, $org, $brand, $other] = $this->prepare();
        $this->timing($org, $brand, $owner, null, null, '09:00');
        $this->at('2026-11-08 10:00');
        $this->emit($org, $brand);
        $this->at('2026-11-08 15:00');
        $this->emit($org, $brand);
        $this->emit($org, $other);
        $this->assertSame(['deferred', 'deferred'], $this->statuses($brand));
        // A brand without personal timing is not affected by another brand's digest.
        $this->assertSame(['sent'], $this->statuses($other));
        $this->assertCount(1, $this->sent);
        app(ExternalDelivery::class)->drain(['pending']);
        $this->assertCount(1, $this->sent);
        $this->at('2026-11-09 09:01');
        app(ExternalDelivery::class)->drain(['pending']);
        $this->assertSame(['sent', 'sent'], $this->statuses($brand));
        $this->assertCount(2, $this->sent);
        $this->assertStringStartsWith('Сводка уведомлений: 2', $this->sent[1]);
        $this->assertSame(2, substr_count($this->sent[1], 'Диалог ждёт менеджера'));
    }

    public function test_timing_of_one_user_does_not_delay_another_user(): void
    {
        $this->at('2026-11-08 20:00');
        [$owner, $org, $brand] = $this->prepare();
        [$member, $membership] = $this->member($org, 'member', [$brand->id]);
        $context = new TenantContext($org->id, $brand->id, $member->id, $membership->id);
        app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'telegram', true, '2002'));
        $this->timing($org, $brand, $owner, '22:00', '08:00', null);
        $this->at('2026-11-08 23:00');
        $this->emit($org, $brand);
        $this->assertSame('deferred', DB::table('notification_deliveries')->where('user_id', $owner->id)->where('channel', 'telegram')->value('status'));
        $this->assertSame('sent', DB::table('notification_deliveries')->where('user_id', $member->id)->where('channel', 'telegram')->value('status'));
        $this->assertCount(1, $this->sent);
    }

    public function test_profile_rejects_invalid_quiet_hours(): void
    {
        [$owner, , $brand] = $this->prepare();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $settings = $this->get('/profile/notifications')->assertOk()->viewData('page')['props']['notificationSettings'];
        $this->assertTrue($settings['timingAvailable']);
        $this->withHeader('X-Brand-Revision', $settings['timingRevision'])->putJson('/profile/notifications/timing', ['quiet_start' => '25:00', 'quiet_end' => '08:00'])->assertUnprocessable()->assertJsonValidationErrors('quiet_start');
        $this->withHeader('X-Brand-Revision', $settings['timingRevision'])->putJson('/profile/notifications/timing', ['quiet_start' => '22:00'])->assertUnprocessable();
        $this->assertDatabaseCount('profile_notification_timing_drafts', 0);
        $this->withHeader('X-Brand-Revision', $settings['timingRevision'])->put('/profile/notifications/timing', ['quiet_start' => '22:00', 'quiet_end' => '08:00', 'digest_time' => '09:00'])
            ->assertRedirect('/profile/notifications')->assertSessionHas('status', 'Настройки тихих часов и сводки сохранены.');
        $this->assertDatabaseHas('profile_notification_timing_drafts', ['user_id' => $owner->id, 'brand_id' => $brand->id, 'quiet_start' => '22:00', 'digest_time' => '09:00']);
    }
}
