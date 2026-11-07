<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftBackupTest extends TenancyTestCase
{
    public function test_backup_includes_draft_and_fact_metadata_without_changing_it(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery', 'topic' => 'Доставка'])->assertSessionHasNoErrors();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'По согласованию'])->assertSessionHasNoErrors();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $response = $this->get('/seller/knowledge/'.$draft->id.'/backup')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $data = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('souzpir-knowledge-draft-v1', $data['format']);
        self::assertSame(3, $data['version']);
        self::assertSame('По согласованию', $data['fact_metadata'][0]['facts'][0]['text']);
        self::assertSame('active', $data['fact_metadata'][0]['facts'][0]['status']);
        self::assertSame('manual', $data['fact_metadata'][0]['facts'][0]['source']);
        $this->assertDatabaseHas('knowledge_releases', ['id' => $draft->id, 'status' => 'draft', 'version' => 3]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/'.$draft->id.'/backup')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/knowledge/'.$draft->id.'/backup')->assertNotFound();
    }
}
