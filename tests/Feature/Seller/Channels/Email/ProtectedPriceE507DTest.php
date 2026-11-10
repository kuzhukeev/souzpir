<?php

namespace Tests\Feature\Seller\Channels\Email;

use App\Seller\Channels\Email\PriceMail;
use App\Seller\Channels\Email\ProtectedPriceSender;
use App\Seller\Conversations\Data\TurnClaim;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProtectedPriceE507DTest extends TenancyTestCase
{
    public function test_fake_price_delivery_replays_and_failure_creates_brand_task(): void
    {
        Mail::fake();
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        config(['runtime.ai_driver' => 'fake', 'seller.email_price_enabled' => true]);
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $setting = ['followup' => '{}', 'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}'];
        DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, ...$setting, 'catalog_prices_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_email_price_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'sender_email' => 'sender@example.test', 'sender_name' => 'Brand', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $path = "seller/catalog/{$org->id}/{$brand->id}/price.csv";
        Storage::disk('local')->put($path, 'SKU;Price');
        DB::table('catalog_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'price_file_path' => $path, 'price_file_name' => 'price.csv', 'created_at' => now(), 'updated_at' => now()]);
        $claim = new TurnClaim('claimed', (string) Str::ulid(), $conversation, $org->id, $brand->id, 0);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $service = app(ProtectedPriceSender::class);
        $responder = (string) Str::ulid();
        $arguments = ['email' => 'buyer@example.test'];
        self::assertSame('fake_sent', $service->send($tenant, $claim, $responder, 'tool-one', $arguments)['status']);
        self::assertSame('fake_sent', $service->send($tenant, $claim, $responder, 'tool-one', $arguments)['status']);
        Mail::assertSent(PriceMail::class, 1);
        self::assertDatabaseHas('seller_tool_call_journal', ['brand_id' => $brand->id, 'tool' => 'send_price', 'turn_id' => $claim->turnId]);
        self::assertSame('unavailable', $service->send(new TenantContext($org->id, $other->id, source: 'job'), $claim, $responder, 'cross-brand', $arguments)['status']);
        self::assertSame(0, DB::table('seller_tasks')->count());
        Storage::disk('local')->delete($path);
        self::assertSame('unavailable', $service->send($tenant, $claim, $responder, 'tool-two', $arguments)['status']);
        self::assertSame('unavailable', $service->send($tenant, $claim, $responder, 'tool-two', $arguments)['status']);
        self::assertSame(1, DB::table('seller_tasks')->where('brand_id', $brand->id)->where('type', 'price_send_failed')->count());
        self::assertSame(0, DB::table('seller_tasks')->where('brand_id', $other->id)->count());
        self::assertStringContainsString('Не удалось отправить прайс', DB::table('seller_tasks')->where('brand_id', $brand->id)->value('payload'));
        Mail::assertSent(PriceMail::class, 1);
    }
}
