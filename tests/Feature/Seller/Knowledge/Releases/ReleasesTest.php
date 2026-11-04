<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReleasesTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_overview_exposes_release_author_and_review_dialog_for_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $this->post("/seller/knowledge/{$draft->id}/publish", ['version' => 1])->assertRedirect();
        $dialog = (string) Str::ulid();
        $message = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $dialog, 'role' => 'agent', 'text' => 'Неточный ответ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('message_reviews')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'message_id' => $message, 'user_id' => $owner->id, 'type' => 'wrong_fact', 'note' => 'Ошибка', 'better_text' => 'Верный ответ', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('releases.0.author', $owner->name)->where('releases.0.version', 1)->where('reviews.0.better_text', 'Верный ответ')->where('reviews.0.conversation_id', $dialog));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->has('reviews', 0)->has('releases', 0));
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/draft')->assertForbidden();
    }

    public function test_draft_publish_conflict_rollback_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('activeId', null)->where('draft', null));
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $this->post('/seller/knowledge/draft')->assertStatus(409);
        $this->post("/seller/knowledge/{$draft->id}/publish", ['version' => 999])->assertStatus(409);
        $this->post("/seller/knowledge/{$draft->id}/publish", ['version' => 1, 'note' => str_repeat('x', 501)])->assertSessionHasErrors('note');
        $this->post("/seller/knowledge/{$draft->id}/publish", ['version' => 1, 'note' => 'Уточнили доставку'])->assertRedirect()->assertSessionHasNoErrors();
        $first = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('publicationNotes.'.$first, 'Уточнили доставку'));
        $this->post("/seller/knowledge/{$draft->id}/publish", ['version' => 2])->assertRedirect();
        $second = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        $this->assertNotSame($first, $second);
        $this->post("/seller/knowledge/{$first}/rollback")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($second, DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('activeId', null)->has('releases', 0));
        $this->post("/seller/knowledge/{$first}/rollback")->assertNotFound();
    }

    public function test_inbox_reads_real_tenant_data(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        foreach ([$brand->id, $other->id] as $brandId) {
            $conversation = (string) Str::ulid();
            $message = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brandId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $conversation, 'role' => 'client', 'text' => 'Вопрос', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $conversation, 'type' => 'unknown_question', 'payload' => json_encode(['question' => 'Вопрос']), 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $conversation, 'role' => 'agent', 'text' => 'Ответ', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('message_reviews')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId, 'message_id' => $message, 'user_id' => $owner->id, 'type' => 'wrong_fact', 'note' => 'Ошибка', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId, 'scores' => '{}', 'issues' => '[{"explanation":"Проверка"}]', 'average' => 3, 'created_at' => now(), 'updated_at' => now()]);
        }
        $answered = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $answered, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $answered, 'role' => 'client', 'text' => 'Другой вопрос', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->has('tasks', 1)->has('reviews', 1)->has('evaluations', 1)->where('coverage', 50));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('coverage', 0));
    }

    public function test_permissions_and_invalid_version(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $this->post("/seller/knowledge/{$draft->id}/publish", ['version' => 0])->assertSessionHasErrors('version');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('canWrite', false));
        $this->post("/seller/knowledge/{$draft->id}/publish", ['version' => 1])->assertForbidden();
        $this->post('/seller/knowledge/draft')->assertForbidden();
    }
}
