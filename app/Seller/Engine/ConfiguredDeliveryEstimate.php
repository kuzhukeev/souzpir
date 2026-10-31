<?php

namespace App\Seller\Engine;

use SensitiveParameter;
use Throwable;

/**
 * Pure calculation using explicitly supplied ranges; configuration alone does
 * not prove its origin, approval, tenant ownership or authority to send it.
 */
final class ConfiguredDeliveryEstimate
{
    /** @return array{status: string, price_minor?: array, days?: array} */
    public function calculate(#[SensitiveParameter] mixed $city, #[SensitiveParameter] mixed $config): array
    {
        try {
            // Validate every rule before returning any range, even when an early
            // rule would match. A partially corrupt configuration is unavailable.
            if (! $this->validConfig($config)) {
                return ['status' => 'not_configured'];
            }
            if (! is_string($city) || strlen($city) > 1024 || ! mb_check_encoding($city, 'UTF-8') || trim($city) === '') {
                return ['status' => 'invalid_city'];
            }
            $normalized = mb_strtolower(trim($city), 'UTF-8');
            foreach ($config['rules'] as $rule) {
                foreach ($rule['city_contains'] as $term) {
                    if (str_contains($normalized, mb_strtolower(trim($term), 'UTF-8'))) {
                        return [
                            'status' => 'ready',
                            'price_minor' => ['min' => $rule['price_minor']['min'], 'max' => $rule['price_minor']['max'], 'currency' => $rule['price_minor']['currency']],
                            'days' => ['min' => $rule['days']['min'], 'max' => $rule['days']['max']],
                        ];
                    }
                }
            }

            return ['status' => 'no_match'];
        } catch (Throwable) {
            // Do not expose configuration, city or parser errors.
            return ['status' => 'not_configured'];
        }
    }

    private function validConfig(#[SensitiveParameter] mixed $config): bool
    {
        if (! $this->keys($config, ['rules']) || ! is_array($config['rules']) || ! array_is_list($config['rules'])
            || count($config['rules']) < 1 || count($config['rules']) > 100) {
            return false;
        }
        foreach ($config['rules'] as $rule) {
            if (! $this->keys($rule, ['city_contains', 'price_minor', 'days'])
                || ! is_array($rule['city_contains']) || ! array_is_list($rule['city_contains'])
                || count($rule['city_contains']) < 1 || count($rule['city_contains']) > 50
                || ! $this->range($rule['price_minor'], true) || ! $this->range($rule['days'], false)) {
                return false;
            }
            foreach ($rule['city_contains'] as $term) {
                if (! is_string($term) || strlen($term) > 256 || ! mb_check_encoding($term, 'UTF-8') || trim($term) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    private function range(#[SensitiveParameter] mixed $range, bool $price): bool
    {
        if (! $this->keys($range, $price ? ['min', 'max', 'currency'] : ['min', 'max'])
            || ! is_int($range['min']) || ! is_int($range['max'])
            || $range['min'] < 0 || $range['max'] < $range['min']) {
            return false;
        }

        return ! $price || (is_string($range['currency']) && preg_match('/\A[A-Z]{3}\z/D', $range['currency']) === 1);
    }

    private function keys(#[SensitiveParameter] mixed $value, array $expected): bool
    {
        if (! is_array($value)) {
            return false;
        }
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
