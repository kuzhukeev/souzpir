<?php

namespace App\Seller\Settings;

use App\Seller\Conversations\Models\SellerModel;

final class BrandSellerSettings extends SellerModel
{
    protected $attributes = [
        'followup' => '{"first_hours":20,"second_hours":null,"max_auto":2,"window":null,"texts":[]}',
        'off_topic' => '{"streak":3,"action":"spam"}',
        'negative' => '{"streak":2}',
        'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}',
        'catalog_prices_enabled' => false,
    ];

    protected function casts(): array
    {
        return ['followup' => 'array', 'off_topic' => 'array', 'negative' => 'array', 'tools' => 'array', 'greetings' => 'array', 'phrases' => 'array', 'schedule' => 'array', 'assignment' => 'array', 'catalog_prices_enabled' => 'boolean'];
    }
}
