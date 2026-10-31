<?php

namespace App\Shell\Profile;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireProfileSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        $availability = app(ProfileSettingsAvailability::class);
        abort_unless($availability->configured(), 404);
        abort_unless($availability->ready(), 503, 'Настройки профиля временно недоступны.');

        return $next($request);
    }
}
