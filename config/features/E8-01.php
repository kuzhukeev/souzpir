<?php

return [
    // Defaults from the eyon visibility measurement protocol, not commercial limits.
    'defaults' => ['temperature' => 0.7, 'max_output_tokens' => 1200, 'parser' => [
        'model' => env('VISION_EVIDENCE_PARSER_MODEL', 'google/gemini-2.5-flash'),
        'temperature' => 0,
        'max_tokens' => 1200,
    ],
        // Technical safeguards, not commercial entitlements.
        'workload' => ['max_questions' => 100, 'max_questions_bytes' => 524288, 'max_slots' => 2000]],
];
