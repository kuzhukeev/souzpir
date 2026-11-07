<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OffTopicTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_rules_saved_in_draft_without_changing_published_and_isolated(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy'] = ['limits' => ['off_topic_streak_silent' => 3, 'negative_streak_escalate' => 2]];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);

            return ['id' => $draft['id'], 'version' => 2];
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('draftPolicy.version', 2));
        $this->post('/seller/settings/off-topic', ['version' => 2, 'off_topic_streak' => 5, 'negative_streak' => 4, 'action' => 'handoff'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draft): void {
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft['id'], true)->policy();
            $this->assertSame(5, $data['limits']['off_topic_streak_silent']);
            $this->assertSame(4, $data['limits']['negative_streak_escalate']);
            $this->assertSame('handoff', $data['off_topic_action']);
            $this->assertSame(3, $repo->published($tenant)->policy()['limits']['off_topic_streak_silent']);
        });
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('draftPolicy', null));
        $this->post('/seller/settings/off-topic', ['version' => 3, 'off_topic_streak' => 5, 'negative_streak' => 4, 'action' => 'spam'])->assertNotFound();
    }

    public function test_validation_conflict_and_access(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, [])));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['version' => 1, 'off_topic_streak' => 0, 'negative_streak' => 2, 'action' => 'spam'];
        $this->post('/seller/settings/off-topic', $input)->assertSessionHasErrors('off_topic_streak');
        $input['off_topic_streak'] = 3;
        $input['action'] = 'invalid';
        $this->post('/seller/settings/off-topic', $input)->assertSessionHasErrors('action');
        $input['action'] = 'spam';
        $input['version'] = 9;
        $this->post('/seller/settings/off-topic', $input)->assertStatus(409);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $input['version'] = 1;
        $this->post('/seller/settings/off-topic', $input)->assertForbidden();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('draftPolicy', null));
    }
}
