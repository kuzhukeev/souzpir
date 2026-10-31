<?php

namespace App\Seller\Engine;

use SensitiveParameter;
use Throwable;

/**
 * Internal, side-effect-free dispatcher only. A caller and supplied data are
 * not proof of authorization, tenant ownership or an approved configuration.
 * This is not the registered ToolHandler/runtime integration.
 */
final class ToolRunner
{
    /** @return array{status: string, price_minor?: array, days?: array} */
    public function run(#[SensitiveParameter] mixed $name, #[SensitiveParameter] mixed $arguments, #[SensitiveParameter] mixed $config): array
    {
        try {
            if ($name !== 'calc_delivery') {
                return ['status' => 'unavailable'];
            }
            if (! (new ToolArguments)->check($name, $arguments)['valid']) {
                return ['status' => 'invalid_arguments'];
            }

            // The donor accepts weight_kg but does not use it in its estimate.
            return (new ConfiguredDeliveryEstimate)->calculate($arguments['city'], $config);
        } catch (Throwable) {
            // Never include caller data or parser errors in a failure result.
            return ['status' => 'unavailable'];
        }
    }
}
