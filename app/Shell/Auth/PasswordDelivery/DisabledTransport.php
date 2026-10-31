<?php

namespace App\Shell\Auth\PasswordDelivery;

use App\Models\User;

final class DisabledTransport implements PasswordTransport
{
    public function send(#[\SensitiveParameter] User $user, #[\SensitiveParameter] string $token): bool
    {
        throw new KnownDeliveryFailure('Password delivery is disabled.');
    }
}
