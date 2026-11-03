<?php

namespace Tests\Feature\Vision\Settings;

use App\Vision\Actions\EffectProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SettingsE812Test extends TenancyTestCase
{
    public function test_six_notification_events_are_scoped_and_validated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $page = $this->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->has('events', 6)->where('events.0.channels.cabinet', true));
        $revision = $page->inertiaProps('revision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $changes = [['event' => 'vision.measurement.completed', 'channel' => 'email', 'enabled' => true]];
        $this->withHeaders($headers)->put('/vision/settings/notifications', ['changes' => $changes])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notification_settings', ['brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => 'vision.measurement.completed', 'channel' => 'email', 'enabled' => true]);
        $this->assertDatabaseMissing('notification_settings', ['brand_id' => $other->id, 'event_key' => 'vision.measurement.completed']);
        $this->withHeaders($headers)->put('/vision/settings/notifications', ['changes' => [['event' => 'bad', 'channel' => 'email', 'enabled' => true]]])->assertSessionHasErrors('changes.0.event');
        $this->withHeaders($headers)->put('/vision/settings/notifications', ['changes' => [['event' => 'vision.measurement.completed', 'channel' => 'bad', 'enabled' => true]]])->assertSessionHasErrors('changes.0.channel');
        [$local] = $this->member($org, 'member', [$brand->id]);
        [$foreign] = $this->member($org, 'member', [$other->id]);
        $this->withHeaders($headers)->put('/vision/settings/recipients', ['members' => [['id' => $local->id, 'cabinet' => true, 'email' => true]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notification_settings', ['brand_id' => $brand->id, 'user_id' => $local->id, 'event_key' => 'vision.report.updated', 'channel' => 'email', 'enabled' => true]);
        $this->withHeaders($headers)->put('/vision/settings/recipients', ['members' => [['id' => $foreign->id, 'cabinet' => true, 'email' => true]]])->assertForbidden();
        $this->post('/logout')->assertRedirect();
        $this->get('/vision/settings')->assertRedirect();
    }

    public function test_empty_action_effect_is_private_to_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('actions')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'title' => 'Чужое', 'status' => 'done', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/actions/effect')->assertOk()->assertInertia(fn ($page) => $page->has('items', 0));
    }

    public function test_effect_requires_matching_measurement_plans(): void
    {
        $action = (object) ['id' => 'a', 'title' => 'Действие', 'completed_at' => '2026-10-05'];
        $rows = [['id' => 'before', 'date' => '2026-10-01', 'signature' => 'same', 'visibility' => 20], ['id' => 'after', 'date' => '2026-10-10', 'signature' => 'same', 'visibility' => 35]];
        $projection = app(EffectProjection::class);
        self::assertSame(15.0, $projection->make([$action], $rows)[0]['delta']);
        $rows[1]['signature'] = 'different';
        self::assertNull($projection->make([$action], $rows)[0]['delta']);
        self::assertNull($projection->make([$action], [])[0]['delta']);
    }
}
