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
        $this->ensure($request);

        return $next($request);
    }

    /** ResolveTenant can run earlier in the middleware priority order. */
    public function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user && $request->hasSession()) {
            $version = $request->session()->get('auth.version');
            if ($version === null && Auth::viaRemember()) {
                app(AuthSessionRegistry::class)->issueRemembered($request, $user);
                $version = $request->session()->get('auth.version');
            }
            if (! is_int($version) || $version !== $user->auth_version) {
                $this->invalidate($request);
                throw new AuthenticationException('Unauthenticated.');
            }
            try {
                app(AuthSessionRegistry::class)->withinActive($request, static fn () => null);
            } catch (AuthenticationException $error) {
                $this->invalidate($request);
                throw $error;
            }
        }
    }

    /** Invalidate a session that failed the registry check, without revoking other devices. */
    public function invalidate(Request $request): void
    {
        app(InvitationExchangeGrants::class)->revoke($request);
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
