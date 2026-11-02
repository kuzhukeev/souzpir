<?php

namespace App\Seller\Engine\Guard;

use App\Seller\Conversations\Data\TurnClaim;
use Illuminate\Http\Request;

/** Supplies only a guard stage revalidated against its responder and turn. */
interface VerifiedSource
{
    public function load(Request $http, TurnClaim $claim, string $stageId): array;
}
