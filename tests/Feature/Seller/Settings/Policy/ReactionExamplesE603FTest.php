<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReactionExamplesE603FTest extends TenancyTestCase
{
    public function test_published_reactions_are_scoped_to_brand(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['fewshot'] = ['examples' => [['tag' => 'opt_out_ack', 'text' => 'Больше не побеспокою', 'client_type' => 'all', 'confidence' => 0.9]]];
            $data['escalation'] = ['reasons' => [['id' => 'negative_sentiment', 'message_idea' => 'Передам менеджеру']]];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page
            ->where('reactionExamples.opt_out_ack.0', 'Больше не побеспокою')->where('reactionExamples.negative_sentiment.0', 'Передам менеджеру'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('reactionExamples', []));
    }
}
