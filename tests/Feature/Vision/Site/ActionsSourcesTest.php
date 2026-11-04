<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\Actions\EffectProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ActionsSourcesTest extends TenancyTestCase
{
    private function enableVision(object $organization, object ...$brands): void
    {
        foreach ($brands as $brand) {
            DB::table('brand_modules')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_history_sources_use_completion_audit_and_current_brand_only(): void
    {
        [$owner, $organization, $brand, $other] = $this->cabinet();
        $this->enableVision($organization, $brand, $other);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/actions', ['title' => 'Первое'])->assertSessionHasNoErrors();
        $id = DB::table('actions')->where('brand_id', $brand->id)->value('id');
        $this->patch('/vision/actions/'.$id, ['status' => 'awaiting_measurement', 'completed_at' => '2026-11-01', 'comment' => 'Сделано'])->assertSessionHasNoErrors();
        $this->getJson('/vision/actions/effect')->assertOk()
            ->assertJsonPath('items.0.id', $id)
            ->assertJsonPath('items.0.completed_by_name', $owner->name)
            ->assertJsonPath('items.0.before', null)
            ->assertJsonPath('items.0.after', null);

        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->getJson('/vision/actions/effect')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_incomplete_action_has_no_author_and_invalid_completion_is_rejected(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->enableVision($organization, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/actions', ['title' => 'Второе'])->assertSessionHasNoErrors();
        $id = DB::table('actions')->where('brand_id', $brand->id)->value('id');
        $this->patch('/vision/actions/'.$id, ['status' => 'awaiting_measurement'])->assertSessionHasErrors('comment');
        $this->getJson('/vision/actions/effect')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_unauthenticated_user_cannot_read_history_sources(): void
    {
        $this->getJson('/vision/actions/effect')->assertUnauthorized();
    }

    public function test_only_same_signature_measurements_are_compared(): void
    {
        $actions = [(object) ['id' => 'a', 'title' => 'Действие', 'completed_at' => '2026-11-02']];
        $rows = [
            ['id' => 'before', 'date' => '2026-11-01', 'signature' => 'same', 'visibility' => 30],
            ['id' => 'after', 'date' => '2026-11-03', 'signature' => 'same', 'visibility' => 45],
        ];
        $item = app(EffectProjection::class)->make($actions, $rows)[0];
        self::assertSame(15.0, $item['delta']);
        self::assertSame('before', $item['before']['id']);
        self::assertSame('after', $item['after']['id']);

        $rows[1]['signature'] = 'other';
        $item = app(EffectProjection::class)->make($actions, $rows)[0];
        self::assertNull($item['before']);
        self::assertNull($item['after']);
        self::assertNull($item['delta']);
    }
}
