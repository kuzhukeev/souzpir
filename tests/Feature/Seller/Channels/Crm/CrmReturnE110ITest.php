<?php

namespace Tests\Feature\Seller\Channels\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class CrmReturnE110ITest extends LimitsTestCase
{
    public function test_secure_configuration_returns_to_crm_without_secret_in_location(): void
    {
        Http::preventStrayRequests();
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        $secret = str_repeat('b', 64);
        $response = $this->post('/integrations/api/webhook-settings', ['url' => 'https://hooks.example.org/hook', 'secret' => $secret,
            'events' => ['seller.contact.captured'], 'return_to' => 'crm']);
        $response->assertRedirect('/seller/integrations/crm');
        self::assertStringNotContainsString($secret, $response->headers->get('Location'));
        self::assertSame(1, DB::table('webhooks')->count());
        Http::assertNothingSent();
    }
}
