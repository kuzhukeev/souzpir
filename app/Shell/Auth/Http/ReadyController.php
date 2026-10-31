<?php

namespace App\Shell\Auth\Http;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReadyController
{
    public function show(Request $request): Response
    {
        return Inertia::render('Shell/Auth/Ready', ['mfaEnabled' => (bool) $request->user()->mfa_confirmed_at]);
    }
}
