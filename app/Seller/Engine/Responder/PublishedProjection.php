<?php

namespace App\Seller\Engine\Responder;

use App\Ai\Protected\ResponderProjection;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;

final class PublishedProjection implements Projection
{
    public function render(KnowledgeSnapshot $snapshot, LeadState $state, Goal $goal, string $name): string
    {
        return app(ResponderProjection::class)->render($snapshot, $state, $goal, $name);
    }
}
