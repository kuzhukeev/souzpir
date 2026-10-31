<?php

namespace App\Shell\Auth;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnforceAuthVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->hasSession()) {
            $version = $request->session()->get('auth.version');
            if ($version === null && Auth::viaRemember()) {
                app(AuthSessionRegistry::class)->issueRemembered($request, $user);
                $version = $request->session()->get('auth.version');
            }
            if (! is_int($version) || $version !== $user->auth_version) {
                Auth::guard()->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                throw new AuthenticationException('Unauthenticated.');
            }
            try {
                app(AuthSessionRegistry::class)->withinActive($request, static fn () => null);
            } catch (AuthenticationException $error) {
                Auth::guard()->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                throw $error;
            }
        }

        return $next($request);
    }
}
