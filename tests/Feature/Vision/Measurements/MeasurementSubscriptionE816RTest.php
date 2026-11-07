<?php

namespace Tests\Feature\Vision\Measurements;

use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\FakeTimingTransport;
use App\Shell\Notifications\MeasurementSubscriptionDelivery;
use App\Shell\Notifications\NotificationProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MeasurementSubscriptionE816RTest extends TenancyTestCase
{
    public function test_subscription_is_personal_scoped_expires_and_sends_only_for_its_completed_run(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        config(['runtime.notification_driver' => 'fake']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $set = (string) Str::ulid();
        $run = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '[{}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $run, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'running', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $rev = $this->get('/vision/runs/'.$run.'/progress')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $rev];
        $url = '/vision/runs/'.$run.'/subscription';
        $this->withHeaders($headers)->getJson($url)->assertOk()->assertJsonPath('subscribed', false);
        $this->withHeaders($headers)->putJson($url, ['enabled' => 'bad'])->assertUnprocessable();
        $this->withHeaders($headers)->putJson($url, ['enabled' => true])->assertOk()->assertJsonPath('subscribed', true);
        $this->assertDatabaseCount('vision_measurement_subscriptions', 1);
        $this->flushHeaders()->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $otherRev = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $other->id, 'X-Brand-Revision' => $otherRev])->getJson($url)->assertNotFound();
        $this->flushHeaders()->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $rev = $this->get('/vision/runs/'.$run.'/progress')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $rev];
        $this->withHeaders($headers)->putJson($url, ['enabled' => false])->assertOk()->assertJsonPath('subscribed', false);
        $this->withHeaders($headers)->putJson($url, ['enabled' => true])->assertOk();
        DB::table('measurements')->where('id', $run)->update(['status' => 'completed']);
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'vision.measurement.completed', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'measurement', 'id' => $run, 'version' => 1], 'data' => ['measurement_id' => $run]]);
        $transport = Mockery::mock(FakeTimingTransport::class);
        $transport->shouldReceive('send')->once()->with($org->id, $brand->id, $owner->id, 'email', ['Замер готов']);
        app()->instance(FakeTimingTransport::class, $transport);
        DB::table('vision_measurement_subscriptions')->where('measurement_id', $run)->update(['expires_at' => now()->subSecond()]);
        app(MeasurementSubscriptionDelivery::class)->deliver($event);
        DB::table('vision_measurement_subscriptions')->where('measurement_id', $run)->update(['expires_at' => now()->addDay()]);
        DB::table('outbox_events')->insert(['id' => $event->envelope['event_id'], 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $this->assertDatabaseMissing('vision_measurement_subscriptions', ['measurement_id' => $run, 'sent_at' => null]);
        $this->withHeaders($headers)->putJson($url, ['enabled' => true])->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->flushHeaders()->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $viewerRev = $this->get('/vision/runs/'.$run.'/progress')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $viewerRev])->getJson($url)->assertOk()->assertJsonPath('subscribed', false);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->flushHeaders()->actingAs($manager)->withSession(['brand_id' => $brand->id])->get('/vision/runs/'.$run.'/progress')->assertForbidden();
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }
}
