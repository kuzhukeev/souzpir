<?php

namespace App\Seller\Conversations\Contracts;

use App\Seller\Conversations\Data\ConversationState;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Tenancy\TenantContext;

interface ConversationRepository
{
    public function find(TenantContext $context, string $id): ConversationState;

    public function claimTurn(#[\SensitiveParameter] TenantContext $context, #[\SensitiveParameter] TurnCommand $command): TurnClaim;

    public function commitTurn(#[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] TurnOutcome $outcome): void;
}
