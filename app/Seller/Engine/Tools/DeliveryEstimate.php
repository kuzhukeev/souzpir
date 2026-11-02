<?php

namespace App\Seller\Engine\Tools;

use App\Seller\Engine\ConfiguredDeliveryEstimate;

final class DeliveryEstimate
{
    public function calculate(string $city, array $rules): array
    {
        $normalized = array_map(fn ($rule) => ['city_contains' => [$rule['city']],
            'price_minor' => ['min' => $rule['price_min'], 'max' => $rule['price_max'], 'currency' => 'RUB'],
            'days' => ['min' => $rule['days_min'], 'max' => $rule['days_max']]], $rules);

        return (new ConfiguredDeliveryEstimate)->calculate($city, ['rules' => $normalized]);
    }
}
