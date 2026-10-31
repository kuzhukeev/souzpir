<?php

namespace App\Shell\Auth\VerificationDelivery;

use App\Models\User;

/** Safe default: never invokes a mail transport. */
final class DisabledTransport implements VerificationTransport
{
    public function send(User $user): bool
    {
        throw new KnownDeliveryFailure('Verification transport unavailable.');
    }
}
