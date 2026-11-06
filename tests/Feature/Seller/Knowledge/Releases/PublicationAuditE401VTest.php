<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicationAuditE401VTest extends TenancyTestCase
{
    public function test_publisher_and_time_are_derived_from_brand_audit(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $this->post('/seller/knowledge/'.$draft->id.'/publish', ['version' => 1])->assertSessionHasNoErrors();
        $published = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        $event = DB::table('audit_logs')->where('brand_id', $brand->id)->where('action', 'knowledge.release.published')->where('subject_id', $published)->first();
        self::assertNotNull($event);
        $rows = $this->get('/seller/knowledge')->assertOk()->inertiaProps('releases');
        $row = collect($rows)->firstWhere('id', $published);
        self::assertSame($owner->name, data_get($row, 'publication_audit.author'));
        self::assertSame($event->created_at, data_get($row, 'publication_audit.at'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->has('releases', 0));
        $this->post('/seller/knowledge/'.$published.'/rollback')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/'.$published.'/rollback')->assertForbidden();
    }
}
