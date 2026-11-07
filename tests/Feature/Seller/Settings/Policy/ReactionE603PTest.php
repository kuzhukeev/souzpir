<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReactionE603PTest extends TenancyTestCase
{
    public function test_draft_reactions_access_isolation_and_validation(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['fewshot']['examples'] = [['tag' => 'decline_off_topic', 'text' => 'Исходная фраза', 'client_type' => 'all', 'confidence' => 0.9]];
            app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['version' => 1, 'tag' => 'decline_off_topic', 'phrases' => ['Новая фраза']];
        $this->post('/seller/settings/reactions', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('draftReactions.decline_off_topic.0', 'Новая фраза'));
        $this->post('/seller/settings/reactions', [...$input, 'version' => 2, 'tag' => 'unknown'])->assertSessionHasErrors('tag');
        $this->post('/seller/settings/reactions', [...$input, 'version' => 2, 'phrases' => ['']])->assertSessionHasErrors('phrases.0');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/settings/reactions', [...$input, 'version' => 2])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/settings/reactions', [...$input, 'version' => 2])->assertNotFound();
    }
}
