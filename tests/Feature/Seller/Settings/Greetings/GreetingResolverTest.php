<?php

namespace Tests\Feature\Seller\Settings\Greetings;

use App\Seller\Settings\Greetings\GreetingResolver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class GreetingResolverTest extends TenancyTestCase
{
    public function test_channel_text_is_scoped_to_brand_and_empty_uses_default(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $greetings = ['telegram' => 'Телеграм', 'widget' => 'Виджет', 'api' => 'API', 'email' => 'Почта', 'whatsapp' => 'WhatsApp'];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/settings/greetings', ['greetings' => $greetings])->assertRedirect()->assertSessionHasNoErrors();
        $resolver = app(GreetingResolver::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        foreach ($greetings as $channel => $text) {
            self::assertSame($text, $resolver->resolve($tenant, $channel));
        }
        self::assertNull($resolver->resolve($tenant, 'unknown'));
        self::assertNull($resolver->resolve(new TenantContext($org->id, $other->id, source: 'job'), 'telegram'));
        $this->post('/seller/settings/greetings', ['greetings' => ['telegram' => '  ']])->assertSessionHasNoErrors();
        self::assertNull($resolver->resolve($tenant, 'telegram'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/settings/greetings', ['greetings' => ['api' => 'x']])->assertForbidden();
        Http::assertNothingSent();
    }
}
