<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RestoreFileE401KTest extends TenancyTestCase
{
    public function test_downloaded_copy_restores_facts_and_saves_current_draft(): void
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
        $json = $this->get('/seller/knowledge/'.$draft->id.'/backup')->streamedContent();
        $edited = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $edited['fact_metadata'][0]['facts'][0]['status'] = 'inactive';
        $json = json_encode($edited, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $file = fn (string $contents) => UploadedFile::fake()->createWithContent('backup.json', $contents);
        $this->post('/seller/knowledge/restore-file', ['backup' => $file('broken')])->assertSessionHasErrors('backup');
        $this->assertDatabaseHas('knowledge_releases', ['id' => $draft->id, 'status' => 'draft']);
        $broken = $edited;
        $broken['fact_metadata'][0]['facts'][0]['logical_id'] = [];
        $this->post('/seller/knowledge/restore-file', ['backup' => $file(json_encode($broken, JSON_THROW_ON_ERROR))])->assertSessionHasErrors('backup');
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/restore-file', ['backup' => $file($json)])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/restore-file', ['backup' => $file('broken')])->assertSessionHasErrors('backup');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/restore-file', ['backup' => $file($json)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('knowledge_releases', ['id' => $draft->id, 'status' => 'building']);
        $new = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        self::assertNotSame($draft->id, $new->id);
        $restored = json_decode($this->get('/seller/knowledge/'.$new->id.'/backup')->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $original = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($original['snapshot'], $restored['snapshot']);
        self::assertSame($original['fact_metadata'][0]['facts'][0]['logical_id'], $restored['fact_metadata'][0]['facts'][0]['logical_id']);
        self::assertSame('manual', $restored['fact_metadata'][0]['facts'][0]['source']);
        self::assertSame('inactive', $restored['fact_metadata'][0]['facts'][0]['status']);
    }
}
