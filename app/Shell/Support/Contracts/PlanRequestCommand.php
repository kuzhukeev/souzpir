<?php

namespace App\Shell\Support\Contracts;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class PlanRequestCommand
{
    public string $planId;

    public string $submissionKey;

    public function __construct(string $planId, string $submissionKey)
    {
        if (! Str::isUlid($planId) || ! Str::isUlid($submissionKey)) {
            throw new InvalidArgumentException('Invalid plan request.');
        }
        $this->planId = strtoupper($planId);
        $this->submissionKey = strtoupper($submissionKey);
    }
}
