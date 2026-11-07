<?php

namespace Tests\Feature\Seller\Settings\Tools;

use App\Shell\Support\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Shell\Support\SupportTestCase;

final class ToolSourcesE604JTest extends SupportTestCase
{
    private function enableSeller(string $organization, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_price_source_is_persisted_scoped_and_validated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enableSeller($org->id, $brand->id);
        $this->enableSeller($org->id, $other->id);
        $this->signIn($owner, $brand);
        $input = ['tools' => ['send_price' => true], 'delivery_rules' => [], 'requisites' => '', 'price_source' => 'link', 'price_url' => 'https://example.test/price'];
        $this->post('/seller/scenario/tools', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('settings.price_source', 'link')->where('settings.price_url', 'https://example.test/price'));
        $this->post('/seller/scenario/tools', [...$input, 'price_url' => 'http://example.test/price'])->assertSessionHasErrors('price_url');
        $this->post('/seller/scenario/tools', [...$input, 'price_source' => 'unknown'])->assertSessionHasErrors('price_source');
        $this->post('/seller/scenario/tools', [...$input, 'price_source' => 'catalog', 'price_url' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('settings.price_source', 'catalog')->where('settings.price_url', ''));
        $this->post('/seller/scenario/tools', [...$input, 'price_source' => 'file', 'price_url' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('settings.price_source', 'file')->where('settings.price_url', ''));
        $this->signIn($owner, $other)->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('settings.price_source', 'file')->where('settings.price_url', ''));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->signIn($viewer, $brand)->post('/seller/scenario/tools', $input)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_action_request_creates_fake_scoped_support_ticket_once(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enableSeller($org->id, $brand->id);
        $this->enableSeller($org->id, $other->id);
        $this->signIn($owner, $brand);
        $payload = ['description' => 'Добавить расчёт упаковки', 'submission_key' => (string) Str::ulid()];
        $this->post('/seller/scenario/tools/request', $payload)->assertRedirect()->assertSessionHas('status');
        $ticket = Ticket::withoutGlobalScopes()->firstOrFail();
        self::assertSame('disabled', $ticket->delivery_status);
        self::assertStringContainsString($brand->name, $ticket->body);
        self::assertStringContainsString($payload['description'], $ticket->body);
        $this->post('/seller/scenario/tools/request', $payload)->assertRedirect();
        self::assertSame(1, Ticket::withoutGlobalScopes()->count());
        $this->postJson('/seller/scenario/tools/request', ['description' => 'мало', 'submission_key' => (string) Str::ulid()])->assertUnprocessable();
        $this->postJson('/seller/scenario/tools/request', [...$payload, 'brand_id' => $other->id])->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->signIn($viewer, $brand)->post('/seller/scenario/tools/request', [...$payload, 'submission_key' => (string) Str::ulid()])->assertForbidden();
        $this->signIn($owner, $other)->post('/seller/scenario/tools/request', [...$payload, 'submission_key' => (string) Str::ulid()])->assertRedirect();
        $this->assertDatabaseHas('tickets', ['user_id' => $owner->id, 'body' => 'Запрос действия v2 бренда «'.$other->name.'»: '.$payload['description']]);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
