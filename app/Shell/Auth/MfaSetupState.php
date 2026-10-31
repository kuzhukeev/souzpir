<?php

namespace App\Shell\Auth;

use Illuminate\Http\Request;

final class MfaSetupState
{
    public function pending(#[\SensitiveParameter] Request $request): ?array
    {
        $setup = $request->session()->get('auth.mfa_setup');
        if (! is_array($setup) || ($setup['user_id'] ?? null) !== $request->user()?->id
            || ! is_int($setup['version'] ?? null) || $setup['version'] !== $request->session()->get('auth.version')
            || ! is_string($setup['secret'] ?? null) || ! preg_match('/\A[A-Z2-7]{16,128}\z/D', $setup['secret'])
            || ! is_int($setup['expires'] ?? null) || $setup['expires'] <= now()->timestamp) {
            $request->session()->forget('auth.mfa_setup');

            return null;
        }

        return $setup;
    }
}
