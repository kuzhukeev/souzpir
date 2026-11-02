<?php

namespace App\Seller\Engine\Guard;

use App\Ai\Protected\SellerGuardStages;
use App\Seller\Conversations\Data\TurnClaim;
use Illuminate\Http\Request;

final class ProtectedSource implements VerifiedSource
{
    public function __construct(private readonly SellerGuardStages $stages) {}

    public function load(Request $http, TurnClaim $claim, string $stageId): array
    {
        [$request, $context, , , , $deterministic, $goal] = $this->stages->load($http, $claim, $stageId);

        return [$request, $context, $deterministic, $goal];
    }
}
