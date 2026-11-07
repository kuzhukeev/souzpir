<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Shell\Support\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Shell\Support\SupportTestCase;

final class FieldRequestE603RTest extends SupportTestCase
{
    public function test_fake_ticket_is_scoped_to_current_brand_and_does_not_send_mail(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->signIn($owner, $brand);
        $payload = ['description' => 'Добавить поле для срока доставки', 'submission_key' => (string) Str::ulid()];
        $this->post('/seller/scenario/fields/request', $payload)->assertRedirect()->assertSessionHas('status');
        $ticket = Ticket::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('disabled', $ticket->delivery_status);
        $this->assertStringContainsString($brand->name, $ticket->body);
        $this->assertStringContainsString($payload['description'], $ticket->body);
        $this->post('/seller/scenario/fields/request', $payload)->assertRedirect();
        $this->assertSame(1, Ticket::withoutGlobalScopes()->count());
        $this->postJson('/seller/scenario/fields/request', ['description' => 'short', 'submission_key' => (string) Str::ulid()])->assertUnprocessable();
        $this->postJson('/seller/scenario/fields/request', [...$payload, 'brand_id' => $other->id])->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->signIn($viewer, $brand)->post('/seller/scenario/fields/request', [...$payload, 'submission_key' => (string) Str::ulid()])->assertForbidden();
        $this->signIn($owner, $other)->post('/seller/scenario/fields/request', [...$payload, 'submission_key' => (string) Str::ulid()])->assertRedirect();
        $this->assertDatabaseHas('tickets', ['user_id' => $owner->id, 'body' => 'Изменение полей бренда «'.$other->name.'»: '.$payload['description']]);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
