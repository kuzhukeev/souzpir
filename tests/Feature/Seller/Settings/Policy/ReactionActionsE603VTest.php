<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReactionActionsE603VTest extends TenancyTestCase
{
    public function test_negative_and_opt_out_use_brand_policy_and_phrases(): void
    {
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['policy'] = ['limits' => ['negative_streak_escalate' => 2]];
        $data['escalation'] = ['reasons' => [['id' => 'negative_sentiment', 'message_idea' => 'Передам менеджеру {response_time}']], 'defaults' => ['response_time_text' => 'сегодня']];
        $data['fewshot']['examples'] = [['tag' => 'opt_out_ack', 'text' => 'Больше не пишем', 'client_type' => 'all', 'confidence' => 1]];
        $snapshot = new KnowledgeSnapshot($data, 'release', 'checksum');
        $policy = new Policy($snapshot);
        $state = new LeadState;
        $negative = ['client_intent' => 'unclear', 'sentiment' => 'negative'];
        $policy->applyExtraction($state, $negative);
        self::assertNotSame('handoff', $policy->decide($state, $negative, false)->kind);
        $policy->applyExtraction($state, $negative);
        $goal = $policy->decide($state, $negative, false);
        self::assertSame('handoff', $goal->kind);
        self::assertSame('negative_sentiment', $goal->extras['reason']);
        self::assertSame('Передам менеджеру сегодня', $goal->extras['message_idea']);
        $refusal = new LeadState;
        $policy->applyExtraction($refusal, ['client_intent' => 'opt_out']);
        $goal = $policy->decide($refusal, ['client_intent' => 'opt_out'], false);
        self::assertTrue($refusal->flag('do_not_disturb'));
        self::assertSame('lost', $refusal->stage());
        self::assertSame('declined', $policy->computeOutcome($refusal));
        self::assertSame(['opt_out_ack'], $goal->fewshotTags);
        self::assertContains('mark_do_not_disturb', $goal->allowedTools);
        self::assertSame('Больше не пишем', $snapshot->fewshot($goal->fewshotTags, null, 6, 0.7)[0]['text']);
    }

    public function test_published_action_display_permissions_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy']['off_topic_action'] = 'handoff';
            $data['escalation']['reasons'] = [['id' => 'negative_sentiment', 'message_idea' => 'Менеджер поможет']];
            $repository = app(KnowledgeRepository::class);
            $draft = $repository->createDraft($tenant, $data);
            $repository->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('reactionActions.negative_sentiment', 'Передать менеджеру')->where('reactionActions.opt_out_ack', 'Не беспокоить')->where('reactionActions.decline_off_topic', 'Передать менеджеру'));
        $this->post('/seller/settings/off-topic', ['version' => 2, 'off_topic_streak' => 3, 'negative_streak' => 0, 'action' => 'spam'])->assertSessionHasErrors('negative_streak');
        $this->post('/seller/settings/reactions', ['version' => 2, 'tag' => 'opt_out_ack', 'phrases' => ['']])->assertSessionHasErrors('phrases.0');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/settings/reactions', ['version' => 2, 'tag' => 'opt_out_ack', 'phrases' => ['Текст']])->assertForbidden();
        $this->actingAs($owner)->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('reactionActions', []));
    }
}
