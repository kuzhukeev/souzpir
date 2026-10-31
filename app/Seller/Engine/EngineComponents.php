<?php

namespace App\Seller\Engine;

final readonly class EngineComponents
{
    public function __construct(public Policy $policy, public Extractor $extractor) {}
}
