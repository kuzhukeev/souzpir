<?php

namespace Tests\Feature\Seller\Channels\Email;

use App\Seller\Channels\Email\PriceMail;
use App\Seller\Channels\Email\PriceMailSender;
use App\Tenancy\TenantContext;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class EmailSettingsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_settings_and_fake_price_mail_are_isolated_by_brand(): void
    {
        Mail::fake();
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/channels/email', ['sender_email' => 'sender@example.com', 'sender_name' => 'Бренд', 'enabled' => true])->assertRedirect();
        $path = "seller/catalog/{$org->id}/{$brand->id}/price.csv";
        Storage::disk('local')->put($path, 'Товар;Цена');
        DB::table('catalog_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'price_file_path' => $path, 'price_file_name' => 'price.csv', 'created_at' => now(), 'updated_at' => now()]);
        $sender = app(PriceMailSender::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $this->assertFalse($sender->send($tenant, 'client@example.com'));
        Mail::assertNothingSent();
        config(['seller.email_price_enabled' => true]);
        $this->assertTrue($sender->send($tenant, 'client@example.com'));
        Mail::assertSent(PriceMail::class, function (PriceMail $mail) use ($path): bool {
            $mail->assertHasAttachment(Attachment::fromStorageDisk('local', $path)->as('price.csv'));

            return $mail->hasTo('client@example.com') && $mail->senderEmail === 'sender@example.com';
        });
        $this->assertFalse($sender->send(new TenantContext($org->id, $other->id, source: 'job'), 'client@example.com'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/email')->assertOk()->assertInertia(fn ($page) => $page->where('settings', null));
        $this->assertFalse($sender->send($tenant, 'invalid'));
    }

    public function test_invalid_input_and_viewer_cannot_change_settings(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/channels/email', ['sender_email' => 'invalid', 'sender_name' => '', 'enabled' => true])->assertSessionHasErrors(['sender_email', 'sender_name']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/email')->assertForbidden();
        $this->post('/seller/channels/email', ['sender_email' => 'sender@example.com', 'sender_name' => 'Бренд', 'enabled' => true])->assertForbidden();
    }
}
