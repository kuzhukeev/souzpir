<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReactionE603UTest extends TenancyTestCase
{
    public function test_idea_is_saved_in_brand_draft_with_access_and_input_checks(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['escalation'] = ['reasons' => [['id' => 'negative_sentiment', 'message_idea' => 'Исходная идея', 'title' => 'Раздражение']]];
            app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $input = ['version' => 1, 'tag' => 'negative_sentiment', 'phrases' => ['Передам менеджеру']];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/settings/reactions', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('draftReactions.negative_sentiment.0', 'Передам менеджеру'));
        $this->post('/seller/settings/reactions', [...$input, 'version' => 2, 'phrases' => []])->assertSessionHasErrors('phrases');
        $this->post('/seller/settings/reactions', [...$input, 'version' => 2, 'phrases' => ['Одна', 'Другая']])->assertSessionHasErrors('phrases');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/settings/reactions', [...$input, 'version' => 2])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/settings/reactions', [...$input, 'version' => 2])->assertNotFound();
    }
}
