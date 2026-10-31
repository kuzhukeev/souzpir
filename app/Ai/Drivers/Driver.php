<?php

namespace App\Ai\Drivers;

interface Driver
{
    /** Normalized result: content, toolCalls, usage, model, latencyMs, costMicros; no raw payload. */
    public function complete(array $body): array;
}
