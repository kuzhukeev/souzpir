<?php

namespace App\Shell\Profile;

use App\Privacy\VerifiedHttps;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A named, direct local HTTP connection may use a memory-only profile shell. */
final class LocalProfileTransport
{
    public static function allows(Request $request): bool
    {
        return app()->environment('local') && strtolower($request->getHost()) === 'souzpir.local'
            && ! VerifiedHttps::allows($request) && VerifiedHttps::directLoopback($request);
    }

    public static function shell(string $screen): Response
    {
        Inertia::encryptHistory(false);
        try {
            return Inertia::render('Shell/Profile/Local', [
                'screen' => $screen,
                // Shared account and tenant data otherwise enter plaintext history.
                'auth' => ['user' => null], 'organization' => null,
                'brand' => ['id' => '', 'name' => 'Личный кабинет', 'theme' => 'neutral'],
                'brands' => [], 'brandAppearance' => null, 'unread' => null,
            ]);
        } finally {
            Inertia::encryptHistory((bool) config('inertia.history.encrypt', false));
        }
    }
}
