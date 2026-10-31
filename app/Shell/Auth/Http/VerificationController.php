<?php

namespace App\Shell\Auth\Http;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class VerificationController
{
    public function notice(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('auth.ready');
        }

        return Inertia::render('Shell/Auth/VerifyEmail', ['email' => $request->user()->email, 'mailConfigured' => (bool) config('features.E1-01.defaults.verification_enabled'), 'status' => session('status')]);
    }

    public function confirm(EmailVerificationRequest $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('auth.ready');
        }

        return Inertia::render('Shell/Auth/VerifyEmail', ['email' => $request->user()->email, 'confirmationUrl' => $request->fullUrl(), 'mailConfigured' => (bool) config('features.E1-01.defaults.verification_enabled'), 'status' => session('status')]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            if (! $request->user()->hasVerifiedEmail()) {
                $request->fulfill();
                app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationId(), actorId: $request->user()->id), new AuditEntry('account.email.verified', 'user', $request->user()->id));
            }
        });
        $token = $request->session()->get('auth.invitation');

        return is_string($token) ? redirect()->route('invitation.show', ['token' => $token]) : redirect()->route('auth.ready');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('auth.ready');
        }
        if (! config('features.E1-01.defaults.verification_enabled')) {
            return back()->with('status', 'Почтовый сервис пока не подключён.');
        }
        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (\Throwable) {
            return back()->with('status', 'Письмо не отправлено. Повторите позже.');
        }

        return back()->with('status', 'Письмо для подтверждения отправлено.');
    }
}
