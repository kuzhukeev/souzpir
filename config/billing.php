<?php

return [
    // Временно: пример условий по решению владельца от 05.11.2026.
    'example_plans' => [
        'start' => ['name' => 'Старт', 'price_rub' => 9900, 'limits' => [
            'brands.max' => 1, 'seller.dialogs_month' => 500, 'vision.questions' => 20, 'vision.models' => 3,
            'vision.runs_per_week' => 1, 'vision.slots_month' => 300, 'ai.units_month' => 800, 'users.max' => 3, 'seller.channels_max' => 2,
        ]],
        'business' => ['name' => 'Бизнес', 'price_rub' => 24900, 'limits' => [
            'brands.max' => 3, 'seller.dialogs_month' => 1000, 'vision.questions' => 30, 'vision.models' => 4,
            'vision.runs_per_week' => 1, 'vision.slots_month' => 1800, 'ai.units_month' => 2800, 'users.max' => 10, 'seller.channels_max' => 4,
        ]],
    ],
    'example_units_per_event' => ['seller.dialogues' => 1, 'vision.slots' => 1],
    // plans[plan ID]: units_per_event[metric key], unit_price.
    'plans' => [],
    // renewals[organization ID][plan ID]: ISO date of the next confirmed renewal.
    'renewals' => [],
];
