<?php

namespace App\Shell\Auth\Http;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\InvitationExchangeGrants;
use App\Shell\Auth\VerificationRequests;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class VerificationController
{
    public function notice(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route(app(InvitationExchangeGrants::class)->invitationId($request) ? 'invitation.show' : 'verification.success');
        }

        $status = session('status');
        if (in_array($status, [VerificationRequests::message('pending'), VerificationRequests::message('sent')], true)
            && ! $request->session()->has('verification_resend_after')) {
            $request->session()->put('verification_resend_after', now()->addSeconds(60)->timestamp);
        }

        return Inertia::render('Shell/Auth/VerifyEmail', ['email' => $request->user()->email, 'mailConfigured' => app(VerificationRequests::class)->available(), 'status' => $status, 'idempotencyKey' => bin2hex(random_bytes(24)), 'resendAfter' => session('verification_resend_after', 0)]);
    }

    public function confirm(EmailVerificationRequest $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route(app(InvitationExchangeGrants::class)->invitationId($request) ? 'invitation.show' : 'auth.ready');
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
        $invitationId = app(InvitationExchangeGrants::class)->invitationId($request);

        return is_string($invitationId) ? redirect()->route('invitation.show') : redirect()->route('verification.success');
    }

    public function success(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return Inertia::render('Shell/Auth/VerifyEmail', ['email' => $request->user()->email, 'verified' => true, 'continueUrl' => route('auth.ready')]);
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $email = mb_strtolower(trim($data['email']));
        if (app(InvitationExchangeGrants::class)->invitationId($request)) {
            throw ValidationException::withMessages(['email' => 'Почта в приглашении не может быть изменена.']);
        }
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $email): void {
            $user = $request->user();
            if (DB::table('users')->where('email', $email)->where('id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'Этот адрес уже используется.']);
            }
            $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();
        });
        $request->session()->forget('verification_resend_after');
        $status = app(VerificationRequests::class)->request($request, bin2hex(random_bytes(24)));

        return redirect()->route('verification.notice')->with('status', VerificationRequests::message($status));
    }

    public function send(Request $request, VerificationRequests $verification): RedirectResponse
    {
        $key = $request->input('idempotency_key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key)) {
            return redirect()->route('verification.notice')->with('status', 'Не удалось подтвердить запрос. Попробуйте ещё раз.');
        }
        $status = $verification->request($request, $key);
        if ($status === 'verified') {
            return redirect()->route(app(InvitationExchangeGrants::class)->invitationId($request) ? 'invitation.show' : 'auth.ready');
        }

        if (in_array($status, ['pending', 'sending', 'transmitting', 'sent'], true)) {
            $request->session()->put('verification_resend_after', now()->addSeconds(60)->timestamp);
        }

        return redirect()->route('verification.notice')->with('status', VerificationRequests::message($status));
    }
}
