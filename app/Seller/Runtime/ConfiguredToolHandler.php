<?php

namespace App\Seller\Runtime;

use App\Seller\Engine\ToolArguments;
use App\Seller\Engine\Tools\DeliveryEstimate;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Seller\Settings\Tools\ToolPreferences;
use App\Tenancy\TenantContext;

/**
 * Reads the current brand configuration for a side-effect-free tool result.
 * A protected turn coordinator must authorize and persist any tool call.
 */
final class ConfiguredToolHandler
{
    public function calculateDelivery(TenantContext $tenant, mixed $arguments): array
    {
        if (! $tenant->organizationId || ! $tenant->brandId
            || ! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            return ['status' => 'unavailable'];
        }
        $settings = app(ToolPreferences::class)->read($tenant);
        if (($settings['tools']['calc_delivery'] ?? false) !== true) {
            return ['status' => 'unavailable'];
        }
        if (! (new ToolArguments)->check('calc_delivery', $arguments)['valid']) {
            return ['status' => 'invalid_arguments'];
        }

        return (new DeliveryEstimate)->calculate($arguments['city'], $settings['delivery_rules']);
    }
}
