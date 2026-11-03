<?php

namespace App\Seller\Settings\Greetings;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class GreetingResolver
{
    private const CHANNELS = ['telegram', 'widget', 'api', 'email', 'whatsapp'];

    public function resolve(TenantContext $tenant, string $channel): ?string
    {
        if (! $tenant->organizationId || ! $tenant->brandId || ! in_array($channel, self::CHANNELS, true)) {
            return null;
        }
        $stored = DB::table('brand_seller_settings')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->value('greetings');
        $settings = is_string($stored) ? json_decode($stored, true) : [];
        $text = is_array($settings) ? ($settings[$channel] ?? null) : null;

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
