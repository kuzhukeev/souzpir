<?php

namespace App\Shell\Auth\PasswordDelivery;

use App\Models\User;

interface PasswordTransport
{
    public function send(#[\SensitiveParameter] User $user, #[\SensitiveParameter] string $token): bool;
}
