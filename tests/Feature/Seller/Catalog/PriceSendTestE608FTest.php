<?php

namespace Tests\Feature\Seller\Catalog;

use App\Seller\Channels\Email\PriceMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PriceSendTestE608FTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_sends_test_price_mail_via_safe_array_transport(): void
    {
        Mail::fake();
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/price-file/send')->assertSessionHasErrors('send');
        Mail::assertNothingSent();
        $this->post('/seller/channels/email', ['sender_email' => 'sender@example.com', 'sender_name' => 'Бренд', 'enabled' => true])->assertRedirect();
        $path = "seller/catalog/{$org->id}/{$brand->id}/price.csv";
        Storage::disk('local')->put($path, 'Товар;Цена');
        DB::table('catalog_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'price_file_path' => $path, 'price_file_name' => 'price.csv', 'created_at' => now(), 'updated_at' => now()]);
        config(['seller.email_price_enabled' => true]);
        $this->post('/seller/integrations/catalog/price-file/send')->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertSent(PriceMail::class, fn (PriceMail $mail): bool => $mail->hasTo($owner->email));
    }

    public function test_viewer_cannot_send_test_price(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/price-file/send')->assertForbidden();
    }
}
