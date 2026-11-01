<?php

namespace App\Shell\Auth\PasswordDelivery;

use App\Shell\Auth\MfaRecoveryTransport;
use Illuminate\Http\Request;

/** No forwarded recovery requests until a dedicated proxy contract is approved. */
final class PasswordRecoveryTransport
{
    public static function allows(Request $request): bool
    {
        foreach (array_keys($request->headers->all()) as $name) {
            $name = strtolower($name);
            if ($name === 'forwarded' || str_starts_with($name, 'x-forwarded-') || $name === 'x-real-ip' || $name === 'cf-connecting-ip') {
                return false;
            }
        }

        return MfaRecoveryTransport::allows($request);
    }
}
