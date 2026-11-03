<?php

namespace Tests\Feature\Seller\Knowledge\Topics;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TopicsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function topic(string $title = 'Доставка'): array
    {
        return ['version' => 1, 'title' => $title, 'description' => 'Когда спрашивают о доставке',
            'examples' => ['Как доставляете?'], 'facts' => [], 'default_answer' => 'Уточним у менеджера.',
            'after_action' => 'bridge', 'after_action_text' => 'В какой город нужна доставка?'];
    }

    public function test_create_edit_and_publish_topic_in_real_knowledge_snapshot(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/topics')->assertOk()->assertInertia(fn ($page) => $page->has('topics', 0)->where('draftVersion', null));
        $this->post('/seller/knowledge/topics/draft')->assertRedirect('/seller/knowledge/topics');
        $this->post('/seller/knowledge/topics', $this->topic())->assertRedirect('/seller/knowledge/topics');
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $data = app(TenantContextRunner::class)->run($context, fn () => app(KnowledgeRepository::class)->forRelease($context, $draft->id, true)->toArray());
        $intent = $data['intents'][0];
        $this->assertSame('Доставка', $intent['title']);
        $this->assertSame('Уточним у менеджера.', $intent['default_answer']);
        $this->assertSame('bridge', $intent['after_action']);
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation, 'role' => 'client', 'text' => 'Когда доставите заказ?', 'meta' => json_encode(['intent' => $intent['id']]), 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/seller/knowledge/topics')->assertOk()->assertInertia(fn ($page) => $page->where('topics.0.id', $intent['id'])->where('draftVersion', 2));
        $this->get('/seller/knowledge/topics')->assertOk()->assertInertia(fn ($page) => $page->where('frequencies.'.$intent['id'], 1)->where('frequentQuestions.'.$intent['id'].'.0', 'Когда доставите заказ?'));
        $changed = $this->topic('Сроки доставки');
        $changed['version'] = 2;
        $changed['after_action'] = 'none';
        $changed['after_action_text'] = '';
        $this->put('/seller/knowledge/topics/'.$intent['id'], $changed)->assertRedirect('/seller/knowledge/topics');
        $published = app(TenantContextRunner::class)->run($context, fn () => app(KnowledgeRepository::class)->publish($context, $draft->id, 3));
        $this->assertSame('Сроки доставки', $published->intent($intent['id'])['title']);
        $this->assertSame('none', $published->intent($intent['id'])['after_action']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.draft.updated']);
    }

    public function test_permissions_brand_isolation_and_bad_input(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/topics/draft')->assertRedirect();
        $this->post('/seller/knowledge/topics', [...$this->topic(), 'description' => ''])->assertSessionHasErrors('description');
        $this->post('/seller/knowledge/topics', [...$this->topic(), 'after_action_text' => ''])->assertSessionHasErrors('after_action_text');
        $this->post('/seller/knowledge/topics', [...$this->topic(), 'facts' => ['foreign_fact']])->assertStatus(422);
        $this->post('/seller/knowledge/topics', $this->topic())->assertRedirect();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/topics')->assertOk()->assertInertia(fn ($page) => $page->has('topics', 0));
        $this->post('/seller/knowledge/topics', $this->topic())->assertStatus(409);
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/topics')->assertOk()->assertInertia(fn ($page) => $page->has('topics', 0)->where('canWrite', false));
        $this->post('/seller/knowledge/topics', $this->topic())->assertForbidden();
    }
}
