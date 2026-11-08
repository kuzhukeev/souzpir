<?php

namespace App\Seller\Runtime;

use App\Seller\Engine\ToolArguments;
use App\Seller\Engine\Tools\DeliveryEstimate;
use App\Seller\Engine\Tools\ToolCallJournal;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Seller\Settings\Tools\ToolPreferences;
use App\Tenancy\TenantContext;

/**
 * Reads the current brand configuration for a side-effect-free tool result.
 * A protected turn coordinator must authorize and persist any tool call.
 */
final class ConfiguredToolHandler
{
    public function calculateDelivery(TenantContext $tenant, mixed $arguments, ?string $dialogId = null, ?string $turnId = null): array
    {
        [$result, $journalStatus] = $this->calculate($tenant, $arguments);
        app(ToolCallJournal::class)->record($tenant, 'calc_delivery', $journalStatus, $dialogId, $turnId);

        return $result;
    }

    /** @return array{0: array, 1: string} the external result unchanged, and the (possibly more specific) status to journal. */
    private function calculate(TenantContext $tenant, mixed $arguments): array
    {
        if (! $tenant->organizationId || ! $tenant->brandId
            || ! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            return [['status' => 'unavailable'], 'unavailable'];
        }
        $settings = app(ToolPreferences::class)->read($tenant);
        if (($settings['tools']['calc_delivery'] ?? false) !== true) {
            // The external contract keeps "unavailable" (unchanged callers); the journal
            // records the more specific "disabled" so the settings screen can tell toggles
            // apart from a missing module or configuration.
            return [['status' => 'unavailable'], 'disabled'];
        }
        if (! (new ToolArguments)->check('calc_delivery', $arguments)['valid']) {
            return [['status' => 'invalid_arguments'], 'invalid_arguments'];
        }
        $result = (new DeliveryEstimate)->calculate($arguments['city'], $settings['delivery_rules']);

        return [$result, $result['status']];
    }
}
