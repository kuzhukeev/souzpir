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

    public function claimTurn(TenantContext $context, TurnCommand $command): TurnClaim;

    /** Replay only an identical already-terminal outcome; new writes require protected server-owned provenance. */
    public function commitTurn(TurnClaim $claim, TurnOutcome $outcome): void;
}
