<?php

namespace Tests\Feature\Seller\Knowledge\Fewshot;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FewshotMoreTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function dialog(string $org, string $brand): array
    {
        $dialog = (string) Str::ulid();
        $message = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org, 'brand_id' => $brand,
            'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => $message, 'organization_id' => $org, 'brand_id' => $brand,
            'conversation_id' => $dialog, 'role' => 'manager', 'text' => 'Уточню условия поставки.',
            'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return [$dialog, $message];
    }

    public function test_bad_examples_and_dialog_message_are_saved_to_brand_draft(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        [$dialog, $message] = $this->dialog($org->id, $brand->id);
        [$foreign] = $this->dialog($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/fewshot/bad', ['version' => 1, 'text' => 'Доставка точно бесплатна.',
            'why' => 'Нельзя обещать без проверки'])->assertRedirect()->assertSessionHasNoErrors();
        $this->getJson('/seller/knowledge/fewshot/dialogs')->assertOk()->assertJsonPath('dialogs.0.id', $dialog);
        $this->getJson('/seller/knowledge/fewshot/dialogs/'.$dialog)->assertOk()->assertJsonPath('messages.0.id', $message);
        $this->getJson('/seller/knowledge/fewshot/dialogs/'.$foreign)->assertNotFound();
        $this->post('/seller/knowledge/fewshot/from-dialog', ['version' => 2, 'dialog' => $dialog, 'message' => $message,
            'tag' => 'ask_city', 'confidence' => 0.9])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/fewshot')->assertOk()->assertInertia(fn ($page) => $page
            ->where('fewshot.bad_examples.0.text', 'Доставка точно бесплатна.')
            ->where('fewshot.examples.0.text', 'Уточню условия поставки.')
            ->where('fewshot.examples.0.source', 'dialog #'.substr($dialog, -6)));
        $this->delete('/seller/knowledge/fewshot/bad', ['version' => 3, 'index' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/fewshot')->assertOk()->assertInertia(fn ($page) => $page->has('fewshot.bad_examples', 0));
    }

    public function test_validation_and_permission_failures(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        [$dialog, $message] = $this->dialog($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/fewshot/bad', ['version' => 1, 'text' => '', 'why' => 'Причина'])->assertSessionHasErrors('text');
        $this->post('/seller/knowledge/fewshot/from-dialog', ['version' => 1, 'dialog' => $dialog, 'message' => $message,
            'tag' => 'ask_city', 'confidence' => 2])->assertSessionHasErrors('confidence');
        $this->post('/seller/knowledge/fewshot/from-dialog', ['version' => 1, 'dialog' => $dialog, 'message' => (string) Str::ulid(),
            'tag' => 'ask_city', 'confidence' => 0.9])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->getJson('/seller/knowledge/fewshot/dialogs')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->getJson('/seller/knowledge/fewshot/dialogs/'.$dialog)->assertNotFound();
    }
}
