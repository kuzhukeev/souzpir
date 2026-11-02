<?php

namespace App\Seller\Engine\Responder;

use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;

interface Projection
{
    public function render(KnowledgeSnapshot $snapshot, LeadState $state, Goal $goal, string $name): string;
}
