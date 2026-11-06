<?php

namespace Tests\Feature\Seller\Knowledge\Prohibitions;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TopicsE410DTest extends TenancyTestCase
{
    public function test_topics_can_be_added_and_removed_in_brand_draft(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['closed_topics'] = [['id' => 'old', 'category' => 'old', 'idea' => 'old idea', 'client_examples' => ['old question']]];
        $data['guard_rules'] = ['forbidden_promises' => []];
        app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, $data));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['version' => 1, 'topics' => [['id' => null, 'category' => 'privacy', 'idea' => 'do not reveal', 'next' => 'return']], 'rules' => [], 'maxWords' => 80];
        $this->post('/seller/knowledge/prohibitions', $input)->assertRedirect()->assertSessionHasNoErrors();
        $topics = $this->get('/seller/knowledge/prohibitions')->assertOk()->inertiaProps('topics');
        self::assertCount(1, $topics);
        self::assertNotSame('old', $topics[0]['id']);
        self::assertSame('privacy', $topics[0]['category']);
        $this->post('/seller/knowledge/prohibitions', [...$input, 'version' => 2, 'topics' => [['id' => 'foreign', 'category' => 'x', 'idea' => 'x', 'next' => 'return']]])->assertSessionHasErrors('topics');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/seller/knowledge/prohibitions', $input)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/prohibitions', $input)->assertForbidden();
    }
}
