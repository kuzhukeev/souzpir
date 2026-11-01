<?php

namespace App\Shell\Auth\PasswordDelivery;

final class ResetToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function valid(#[\SensitiveParameter] mixed $token): bool
    {
        return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1;
    }
}
