<?php

namespace App\Shell\Profile;

use App\Shell\Profile\Security\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocalProfileDataController
{
    public function __invoke(#[\SensitiveParameter] Request $request, string $screen): JsonResponse
    {
        abort_unless(LocalProfileTransport::allows($request), 404);
        $payload = match ($screen) {
            'status' => app(ProfileAccess::class)->run($request, static fn (): array => ['ok' => true]),
            'profile' => app(ProfileController::class)->data($request),
            'notifications' => app(NotificationController::class)->data($request),
            'security' => ['security' => [...app(SecuritySettings::class)->view($request), 'setup' => null]],
            default => abort(404),
        };

        return response()->json($payload, 200, [
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Cookie',
        ]);
    }
}
