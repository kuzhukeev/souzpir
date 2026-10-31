<?php

namespace App\Shell\Auth;

use App\Privacy\VerifiedHttps;
use Illuminate\Http\Request;

final class MfaRecoveryTransport
{
    public static function local(Request $request): bool
    {
        return app()->environment('local') && strtolower($request->getHost()) === 'souzpir.local'
            && VerifiedHttps::directLoopback($request);
    }

    public static function allows(Request $request): bool
    {
        return SecureAccountContext::allows($request) || self::local($request);
    }
}
