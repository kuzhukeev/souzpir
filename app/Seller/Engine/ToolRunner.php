<?php

namespace App\Seller\Engine;

use Carbon\CarbonImmutable;
use SensitiveParameter;
use Throwable;

/**
 * Internal, side-effect-free dispatcher only. A caller and supplied data are
 * not proof of authorization, tenant ownership or an approved configuration.
 * This is not the registered ToolHandler/runtime integration. A follow-up result
 * is only a proposed date, never a saved task or proof of customer consent.
 */
final class ToolRunner
{
    /** @return array<string, mixed> */
    public function run(#[SensitiveParameter] mixed $name, #[SensitiveParameter] mixed $arguments, #[SensitiveParameter] mixed $config): array
    {
        try {
            if ($name === 'set_followup' && is_array($config) && ($config['now'] ?? null) instanceof CarbonImmutable) {
                if (! (new ToolArguments)->check($name, $arguments)['valid']) {
                    return ['status' => 'invalid_arguments'];
                }
                $candidate = (new FollowupDate)->parse($arguments['datetime'], $config['now']);
                if ($candidate['status'] !== 'candidate') {
                    return ['status' => 'needs_clarification'];
                }

                return [
                    'status' => 'candidate',
                    'datetime' => $candidate['date']->toIso8601String(),
                    'time_defaulted' => $candidate['time_defaulted'],
                    'day_defaulted' => $candidate['day_defaulted'],
                    'year_defaulted' => $candidate['year_defaulted'],
                ];
            }
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
