<?php

namespace App\Shell\Auth\Http;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\VerificationRequests;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class VerificationController
{
    public function notice(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route(is_string($request->session()->get('auth.invitation_id')) ? 'invitation.show' : 'auth.ready');
        }

        return Inertia::render('Shell/Auth/VerifyEmail', ['email' => $request->user()->email, 'mailConfigured' => app(VerificationRequests::class)->available(), 'status' => session('status'), 'idempotencyKey' => bin2hex(random_bytes(24))]);
    }

    public function confirm(EmailVerificationRequest $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route(is_string($request->session()->get('auth.invitation_id')) ? 'invitation.show' : 'auth.ready');
        }

        return Inertia::render('Shell/Auth/VerifyEmail', ['email' => $request->user()->email, 'confirmationUrl' => $request->fullUrl(), 'mailConfigured' => (bool) config('features.E1-01.defaults.verification_enabled'), 'status' => session('status')]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($request): void {
            if (! $request->user()->hasVerifiedEmail()) {
                $request->fulfill();
                app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationId(), actorId: $request->user()->id), new AuditEntry('account.email.verified', 'user', $request->user()->id));
            }
        });
        $invitationId = $request->session()->get('auth.invitation_id');

        return is_string($invitationId) ? redirect()->route('invitation.show') : redirect()->route('auth.ready');
    }

    public function send(Request $request, VerificationRequests $verification): RedirectResponse
    {
        $key = $request->input('idempotency_key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key)) {
            return redirect()->route('verification.notice')->with('status', 'Не удалось подтвердить запрос. Попробуйте ещё раз.');
        }
        $status = $verification->request($request, $key);
        if ($status === 'verified') {
            return redirect()->route(is_string($request->session()->get('auth.invitation_id')) ? 'invitation.show' : 'auth.ready');
        }

        return redirect()->route('verification.notice')->with('status', VerificationRequests::message($status));
    }
}
