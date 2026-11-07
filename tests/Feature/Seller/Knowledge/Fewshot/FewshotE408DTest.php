<?php

namespace Tests\Feature\Seller\Knowledge\Fewshot;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FewshotE408DTest extends TenancyTestCase
{
    public function test_persona_limit_and_samples_are_scoped_and_long_sample_can_be_saved(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id, 'job');
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $draft = DB::table('knowledge_releases')->where('brand_id', $tenant->brandId)->where('status', 'draft')->sole();
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $data['persona']['length']['max_words'] = 2;
            $repo->saveDraft($tenant, $draft->id, (int) $draft->version, $data);
        });
        $this->get('/seller/knowledge/fewshot', ['X-Inertia-Partial-Component' => 'Seller/Knowledge/Fewshot/Index', 'X-Inertia-Partial-Data' => 'fewshotData'])->assertOk()->assertInertia(fn ($page) => $page->where('fewshotData.maxWords', 2)->where('fewshotData.draft.version', 2));
        $input = ['version' => 2, 'bucket' => 'examples', 'tag' => 'ask_city', 'text' => 'Уточните пожалуйста город доставки', 'client_type' => 'all', 'confidence' => 0.8, 'enabled' => true];
        $this->post('/seller/knowledge/fewshot', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/fewshot', ['X-Inertia-Partial-Component' => 'Seller/Knowledge/Fewshot/Index', 'X-Inertia-Partial-Data' => 'fewshotData'])->assertOk()->assertInertia(fn ($page) => $page->where('fewshotData.fewshot.examples.0.text', $input['text'])->where('fewshotData.maxWords', 2));
        $this->post('/seller/knowledge/fewshot', [...$input, 'version' => 3, 'confidence' => 2, 'text' => ''])->assertSessionHasErrors(['confidence', 'text']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/fewshot', ['X-Inertia-Partial-Component' => 'Seller/Knowledge/Fewshot/Index', 'X-Inertia-Partial-Data' => 'fewshotData'])->assertOk()->assertInertia(fn ($page) => $page->where('fewshotData.draft', null)->where('fewshotData.maxWords', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/fewshot')->assertForbidden();
        $this->post('/seller/knowledge/fewshot', [...$input, 'version' => 3])->assertForbidden();
    }
}
