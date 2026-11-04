<?php

return [
    // Only operator-confirmed values. No demonstration prices or implicit renewals.
    // plans[plan ID]: units_per_event[metric key], unit_price.
    'plans' => [],
    // renewals[organization ID][plan ID]: ISO date of the next confirmed renewal.
    'renewals' => [],
];
