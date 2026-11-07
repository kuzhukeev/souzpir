<?php

return [
    'defaults' => [
        // /health degrades when the oldest pending job is older than this or
        // when there are more failed jobs than this, so a stuck worker trips it.
        'queue_lag_warn_seconds' => 300,
        'failed_jobs_warn_count' => 20,
    ],
];
