<?php

namespace Tests\Feature\Vision\Sources;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SourceCitationsActionsE820NTest extends TenancyTestCase
{
    public function test_confirmed_citations_and_saved_action_are_scoped_to_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $description = 'Проверить присутствие бренда на площадке example.test.';
        $otherId = (string) Str::ulid();
        foreach ([[$other->id, $otherId, 'completed']] as [$brandId, $id, $status]) {
            DB::table('actions')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId, 'title' => 'Проверить профиль', 'description' => $description, 'status' => $status, 'completed_at' => $status === 'completed' ? today() : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->snapshot(['metrics' => ['sources' => [['domain' => 'example.test', 'answers' => 1]], 'slots' => [
            ['valid' => true, 'provider' => 'fake', 'sources' => [['domain' => 'example.test', 'url' => 'https://example.test/1'], ['domain' => 'example.test', 'url' => 'https://example.test/2']]],
            ['valid' => false, 'sources' => [['domain' => 'example.test', 'url' => 'https://example.test/3']]],
        ]]]);

        $this->get('/vision/sources')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.action_id', null));
        $this->post('/vision/actions', ['title' => 'Проверить профиль на example.test', 'description' => $description])->assertRedirect('/vision/actions');
        $ownId = DB::table('actions')->where('brand_id', $brand->id)->value('id');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.answers', 1)->where('sources.0.citations', 2)->where('sources.0.action_id', $ownId)->where('sources.0.action_status', 'open'));
    }

    public function test_missing_citation_evidence_is_reported_as_unavailable(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->snapshot(['metrics' => ['sources' => [['domain' => 'example.test', 'answers' => 1]]]]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.citations', null)->where('sources.0.action_id', null));
        $this->post('/vision/sources/presence', ['domain' => 'other.test', 'presence' => 'present'])->assertSessionHasErrors('domain');
        $this->post('/vision/sources/presence', ['domain' => 'example.test', 'presence' => 'invalid'])->assertSessionHasErrors('presence');
    }

    private function snapshot(array $snapshot): void
    {
        app()->instance(SnapshotView::class, new class($snapshot) extends SnapshotView
        {
            public function __construct(private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $this->snapshot;
            }
        });
    }
}
