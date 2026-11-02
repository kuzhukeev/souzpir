<?php

namespace Tests\Feature\Seller\Channels\Widget;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetContactTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function input(): array
    {
        return ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Связаться', 'color' => '#225544', 'position' => 'right'],
            'behavior' => ['delay_seconds' => 5, 'scroll_percent' => 50, 'invitation' => 'Здравствуйте!', 'collect_contact' => true, 'consent_url' => 'https://example.test/privacy', 'exit_intent' => true, 'page_mask' => '/catalog/*', 'repeat_hours' => 24]];
    }

    public function test_contact_requires_consent_valid_origin_and_is_visible_to_its_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/widget')->assertOk();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $url = '/widget/contact?key='.$key;
        $this->call('OPTIONS', $url, [], [], [], ['HTTP_ORIGIN' => 'https://example.test'])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://example.test');
        $this->withHeader('Origin', 'https://wrong.test')->postJson($url, ['consent' => true, 'email' => 'client@example.com'])->assertNotFound();
        $this->withHeader('Origin', 'https://example.test')->postJson($url, ['consent' => false, 'email' => 'client@example.com'])->assertUnprocessable();
        $this->postJson($url, ['consent' => true, 'email' => 'bad'])->assertUnprocessable();
        $this->postJson($url, ['consent' => true, 'email' => 'client@example.com', 'name' => 'Клиент'])->assertCreated()->assertHeader('Access-Control-Allow-Origin', 'https://example.test');
        $this->assertDatabaseHas('widget_contact_requests', ['brand_id' => $brand->id, 'email' => 'client@example.com']);
        $this->get('/seller/channels/widget')->assertOk()->assertInertia(fn ($page) => $page->has('contactRequests', 1)->where('contactRequests.0.email', 'client@example.com'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/widget')->assertOk()->assertInertia(fn ($page) => $page->has('contactRequests', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/widget')->assertForbidden();
    }

    public function test_contact_collection_requires_policy_link(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = $this->input();
        $input['behavior']['consent_url'] = '';
        $this->post('/seller/channels/widget', $input)->assertSessionHasErrors('behavior.consent_url');
        $input['behavior']['consent_url'] = 'http://example.test/privacy';
        $this->post('/seller/channels/widget', $input)->assertSessionHasErrors('behavior.consent_url');
    }
}
