<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MessageTemplatesE504QTest extends TenancyTestCase
{
    public function test_templates_are_editable_isolated_and_validated(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->put('/seller/dialogs/templates', ['templates' => [['name' => 'Ответ', 'text' => 'Здравствуйте!']]])->assertRedirect();
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn ($page) => $page->where('messageTemplates.0.name', 'Ответ')->where('messageTemplates.0.text', 'Здравствуйте!'));
        $this->putJson('/seller/dialogs/templates', ['templates' => [['name' => '', 'text' => 'Текст']]])->assertUnprocessable();
        $this->putJson('/seller/dialogs/templates', ['templates' => [['name' => 'A', 'text' => str_repeat('x', 5001)]]])->assertUnprocessable();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn ($page) => $page->where('messageTemplates.0.name', 'Приветствие'));
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->put('/seller/dialogs/templates', ['templates' => []])->assertForbidden();
        $this->actingAs($owner)->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $this->put('/seller/dialogs/templates', ['templates' => []])->assertRedirect();
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn ($page) => $page->has('messageTemplates', 0));
    }
}
