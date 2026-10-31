<?php

namespace App\Shell\Auth\VerificationDelivery;

use App\Models\User;

/** Return true only on confirmed transport acceptance; false is uncertain. Throw KnownDeliveryFailure only before transmission. */
interface VerificationTransport
{
    public function send(User $user): bool;
}
