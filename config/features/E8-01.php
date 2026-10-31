<?php

return [
    // Defaults from the eyon visibility measurement protocol, not commercial limits.
    'defaults' => ['temperature' => 0.7, 'max_output_tokens' => 1200, 'parser' => null,
        // Technical safeguards, not commercial entitlements.
        'workload' => ['max_questions' => 100, 'max_questions_bytes' => 524288, 'max_slots' => 2000]],
];
