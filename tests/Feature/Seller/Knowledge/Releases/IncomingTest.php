<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class IncomingTest extends TenancyTestCase
{
    public function test_incoming_can_be_attached_or_merged_only_with_current_brand_topic(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        DB::table('kn_intents')->insert(['id' => (string) Str::ulid(), 'release_id' => $draft, 'key' => 'delivery', 'position' => 0,
            'data' => json_encode(['id' => 'delivery', 'description' => 'Доставка', 'examples' => []])]);
        $id = $this->evaluation($org->id, $brand->id);
        $foreign = $this->evaluation($org->id, $other->id);
        $payload = ['type' => 'evaluation', 'id' => $id, 'decision' => 'attach', 'existing_topic_id' => 'delivery', 'version' => 1, 'text' => 'Когда доставка?'];
        $this->post('/seller/knowledge/incoming', [...$payload, 'id' => $foreign])->assertNotFound();
        $this->post('/seller/knowledge/incoming', [...$payload, 'existing_topic_id' => 'missing'])->assertStatus(422);
        $this->post('/seller/knowledge/incoming', [...$payload, 'text' => str_repeat('x', 201)])->assertSessionHasErrors('text');
        $this->post('/seller/knowledge/incoming', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $topic = json_decode(DB::table('kn_intents')->where('release_id', $draft)->value('data'), true);
        $this->assertSame(['Когда доставка?'], $topic['examples']);
        $ban = $this->evaluation($org->id, $brand->id);
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $ban, 'decision' => 'accept', 'target' => 'closed_topic', 'version' => 2, 'group' => 'commercial', 'text' => 'Не обещать скидку'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Не обещать скидку', json_decode(DB::table('kn_closed_topics')->where('release_id', $draft)->value('data'), true)['idea']);
        $another = $this->evaluation($org->id, $brand->id);
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $another, 'decision' => 'merge', 'existing_topic_id' => 'delivery'])->assertRedirect()->assertSessionHasNoErrors();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/incoming', [...$payload, 'id' => $this->evaluation($org->id, $brand->id)])->assertForbidden();
    }

    private function evaluation(string $org, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('seller_evaluations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'scores' => '{}', 'issues' => '[{"type":"unknown_fact"}]', 'average' => 3, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_accept_reject_version_access_and_brand(): void
    {
        [$owner,$org,$brand,$other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $one = $this->evaluation($org->id, $brand->id);
        $two = $this->evaluation($org->id, $brand->id);
        $foreign = $this->evaluation($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'info'])->assertRedirect();
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $one, 'decision' => 'accept', 'version' => 1, 'group' => 'info', 'text' => 'Проверенный факт'])->assertStatus(409);
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $foreign, 'decision' => 'accept', 'version' => 2, 'group' => 'info', 'text' => 'Чужой'])->assertNotFound();
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $one, 'decision' => 'accept', 'version' => 2, 'group' => 'info', 'text' => 'Проверенный факт'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('kn_facts', ['text' => 'Проверенный факт']);
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $two, 'decision' => 'reject'])->assertRedirect();
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->has('evaluations', 0));

        $condition = $this->evaluation($org->id, $brand->id);
        $example = $this->evaluation($org->id, $brand->id);
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $condition, 'decision' => 'accept', 'target' => 'condition', 'version' => 3, 'group' => 'reserve_days', 'text' => '7'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $example, 'decision' => 'accept', 'target' => 'example', 'version' => 4, 'group' => 'ask_city', 'text' => 'В какой город?'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(7, json_decode(DB::table('kn_conditions')->value('data'), true)['reserve_days']);
        $this->assertSame('В какой город?', json_decode(DB::table('kn_fewshot')->value('data'), true)['examples'][0]['text']);

        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $one, 'decision' => 'accept', 'version' => 3, 'group' => 'info', 'text' => 'Повтор'])->assertNotFound();
        $this->post('/seller/knowledge/incoming', ['type' => 'bad', 'id' => $one, 'decision' => 'reject'])->assertSessionHasErrors('type');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/incoming', ['type' => 'evaluation', 'id' => $two, 'decision' => 'reject'])->assertForbidden();
    }
}
