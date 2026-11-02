<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class IncomingTest extends TenancyTestCase
{
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
