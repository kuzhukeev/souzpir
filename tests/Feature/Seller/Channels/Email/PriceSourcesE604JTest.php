<?php

namespace Tests\Feature\Seller\Channels\Email;

use App\Seller\Channels\Email\EmailDeliverySettings;
use App\Seller\Channels\Email\PriceMail;
use App\Seller\Channels\Email\PriceMailSender;
use App\Seller\Engine\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PriceSourcesE604JTest extends TenancyTestCase
{
    public function test_link_and_catalog_delivery_are_scoped_and_preserve_signature(): void
    {
        Mail::fake();
        config(['seller.email_price_enabled' => true]);
        [$owner, $org, $brand, $other] = $this->cabinet();
        $scope = ['organization_id' => $org->id, 'brand_id' => $brand->id];
        $base = ['id' => (string) Str::ulid(), ...$scope, 'created_at' => now(), 'updated_at' => now()];
        DB::table('seller_email_price_settings')->insert($base + ['sender_email' => 'sender@example.com', 'sender_name' => 'Бренд', 'enabled' => true]);
        DB::table('seller_tool_preferences')->insert($base + ['payload' => json_encode(['price_source' => 'link', 'price_url' => 'https://example.com/price'])]);
        $settings = ['followup' => '{}', 'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}'];
        DB::table('brand_seller_settings')->insert($base + $settings + ['catalog_prices_enabled' => true]);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        app(EmailDeliverySettings::class)->save($tenant, ['mode' => 'eyon', 'signature' => 'С уважением'], null);
        $sender = app(PriceMailSender::class);
        $runner = app(ToolRunner::class);
        self::assertTrue($sender->send($tenant, 'client@example.com'));
        Mail::assertSent(PriceMail::class, fn (PriceMail $mail) => $mail->priceUrl === 'https://example.com/price' && $mail->attachments() === [] && str_contains($mail->content()->htmlString, 'С уважением'));
        self::assertSame(['status' => 'fake_sent', 'source' => 'link'], $runner->run('send_price', ['email' => 'client@example.com'], $tenant));
        self::assertFalse($sender->send(new TenantContext($org->id, $other->id, source: 'job'), 'client@example.com'));
        DB::table('seller_tool_preferences')->where($scope)->update(['payload' => json_encode(['price_source' => 'link', 'price_url' => 'http://example.com/price'])]);
        self::assertFalse($sender->send($tenant, 'client@example.com'));
        self::assertSame(['status' => 'unavailable'], $runner->run('send_price', ['email' => 'client@example.com'], $tenant));
        DB::table('seller_tool_preferences')->where($scope)->update(['payload' => json_encode(['price_source' => 'catalog'])]);
        self::assertFalse($sender->send($tenant, 'client@example.com'));
        $import = (string) Str::ulid();
        DB::table('catalog_imports')->insert(['id' => $import, ...$scope, 'filename' => 'items.csv', 'status' => 'completed', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_items')->insert(['id' => (string) Str::ulid(), ...$scope, 'import_id' => $import, 'sku' => 'A1', 'name' => 'Товар', 'price' => 100, 'created_at' => now(), 'updated_at' => now()]);
        self::assertTrue($sender->send($tenant, 'client@example.com'));
        Mail::assertSent(PriceMail::class, fn (PriceMail $mail) => $mail->catalogCsv !== null && str_contains($mail->catalogCsv, 'A1') && str_contains($mail->catalogCsv, 'Товар') && count($mail->attachments()) === 1 && $mail->signature === 'С уважением');
        self::assertSame(['status' => 'fake_sent', 'source' => 'catalog'], $runner->run('send_price', ['email' => 'client@example.com'], $tenant));
    }
}
