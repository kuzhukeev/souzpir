<?php

namespace Tests\Feature\Seller\Channels\Email;

use App\Seller\Channels\Email\PriceMail;
use App\Seller\Channels\Email\PriceMailSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class EmailSettingsE507CTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_smtp_settings_signature_and_fake_test_are_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $data = ['sender_email' => 'sender@example.com', 'sender_name' => 'Бренд', 'enabled' => true, 'mode' => 'smtp', 'signature' => 'С уважением', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_username' => 'user', 'smtp_password' => 'secret-value', 'smtp_security' => 'tls'];
        $this->post('/seller/channels/email', $data)->assertRedirect()->assertSessionHasNoErrors();
        $row = DB::table('channel_accounts')->where('brand_id', $brand->id)->where('type', 'email')->first();
        self::assertNotSame('secret-value', $row->credentials);
        self::assertSame('secret-value', Crypt::decryptString($row->credentials));
        $this->get('/seller/channels/email')->assertOk()->assertDontSee('secret-value')->assertInertia(fn ($page) => $page->where('delivery.mode', 'smtp')->where('delivery.signature', 'С уважением')->where('delivery.password_set', true));
        $data['smtp_password'] = '';
        $this->post('/seller/channels/email', $data)->assertSessionHasNoErrors();
        self::assertSame('secret-value', Crypt::decryptString(DB::table('channel_accounts')->where('id', $row->id)->value('credentials')));
        $this->post('/seller/channels/email/test', ['recipient' => 'client@example.com'])->assertRedirect()->assertSessionHas('status', 'Тест выполнен в безопасном режиме: письмо не отправлено.');
        $this->post('/seller/channels/email/test', ['recipient' => 'invalid'])->assertSessionHasErrors('recipient');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/email')->assertOk()->assertInertia(fn ($page) => $page->where('settings', null)->where('delivery.mode', 'eyon')->where('delivery.password_set', false));
        $this->post('/seller/channels/email/test', ['recipient' => 'client@example.com'])->assertSessionHasErrors('recipient');
        $this->post('/seller/channels/email', array_merge($data, ['smtp_host' => '', 'smtp_port' => 0]))->assertSessionHasErrors(['smtp_host', 'smtp_port']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/channels/email/test', ['recipient' => 'client@example.com'])->assertForbidden();
        $this->post('/seller/channels/email', $data)->assertForbidden();
    }

    public function test_signature_is_escaped_in_price_mail(): void
    {
        $mail = new PriceMail('price.csv', 'price.csv', 'sender@example.com', 'Бренд', "<script>\nПодпись");
        self::assertStringContainsString('&lt;script&gt;<br', $mail->content()->htmlString);
    }

    public function test_smtp_delivery_uses_saved_brand_settings_only_when_enabled(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/channels/email', ['sender_email' => 'sender@example.com', 'sender_name' => 'Бренд', 'enabled' => true, 'mode' => 'smtp', 'signature' => 'Подпись', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 465, 'smtp_username' => 'user', 'smtp_password' => 'secret', 'smtp_security' => 'ssl'])->assertSessionHasNoErrors();
        Storage::disk('local')->put('price.csv', 'price');
        DB::table('catalog_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'price_file_path' => 'price.csv', 'price_file_name' => 'price.csv', 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $mailer = \Mockery::mock();
        $pending = \Mockery::mock();
        $mailer->shouldReceive('to')->once()->with('client@example.com')->andReturn($pending);
        $pending->shouldReceive('send')->once()->with(\Mockery::on(fn ($mail) => $mail instanceof PriceMail && $mail->signature === 'Подпись'));
        Mail::shouldReceive('build')->once()->with(\Mockery::on(fn ($config) => $config['host'] === 'smtp.example.com' && $config['port'] === 465 && $config['scheme'] === 'smtps' && $config['password'] === 'secret'))->andReturn($mailer);
        self::assertFalse(app(PriceMailSender::class)->send($tenant, 'client@example.com'));
        config(['seller.email_price_enabled' => true]);
        self::assertTrue(app(PriceMailSender::class)->send($tenant, 'client@example.com'));
    }
}
