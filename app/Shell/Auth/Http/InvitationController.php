<?php

namespace App\Shell\Auth\Http;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\VerificationRequests;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Cabinet;
use App\Tenancy\Invitations\AcceptsInvitations;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class InvitationController
{
    private function invite(string $token): Invitation
    {
        abort_unless((bool) preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $org = app(Cabinet::class)->organizationId();
        abort_unless(Organization::whereKey($org)->where('status', 'active')->exists(), 404);

        return Invitation::withoutGlobalScopes()->where('organization_id', $org)->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->firstOrFail();
    }

    public function show(Request $request, string $token, AcceptsInvitations $acceptance): Response
    {
        $invitation = $this->invite($token);
        if ($request->user()) {
            $acceptance->inspect($request->user(), $token);
        } else {
            $request->session()->put('url.intended', route('invitation.show', ['token' => $token]));
        }

        return Inertia::render('Shell/Auth/Invitation', [
            'token' => $token, 'email' => $invitation->email, 'role' => $invitation->role,
            'authenticated' => (bool) $request->user(), 'verified' => (bool) $request->user()?->hasVerifiedEmail(),
        ]);
    }

    public function register(Request $request, string $token, AcceptsInvitations $acceptance, SessionController $session): RedirectResponse
    {
        $invite = $this->invite($token);
        $validator = Validator::make($request->all(), ['name' => ['required', 'string', 'max:120'], 'password' => ['required', 'confirmed', Password::min(12)], 'terms' => ['accepted']]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except(['password', 'password_confirmation']));
        }
        $data = $validator->validated();
        $user = DB::transaction(function () use ($invite, $data, $token, $acceptance): User {
            // No existing user is locked in guest registration: organization precedes invitation.
            abort_unless(Organization::whereKey($invite->organization_id)->where('status', 'active')->lockForUpdate()->first(), 404);
            $locked = Invitation::withoutGlobalScopes()->whereKey($invite->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->accepted_at === null && $locked->revoked_at === null && $locked->expires_at->isFuture(), 404);
            if (User::where('email', $locked->email)->exists()) {
                abort(409, 'Для этого адреса уже есть учётная запись. Войдите, чтобы принять приглашение.');
            }
            $user = User::create(['name' => $data['name'], 'email' => $locked->email, 'password' => $data['password']]);
            $user->forceFill(['terms_accepted_at' => now()])->save();
            $acceptance->inspect($user, $token);
            app(AuditWriter::class)->record(new TenantContext($locked->organization_id, actorId: $user->id), new AuditEntry('account.invitation_registered', 'user', $user->id));

            return $user;
        });
        $session->complete($request, $user, false);
        $request->session()->put('auth.invitation', $token);
        try {
            $status = app(VerificationRequests::class)->request($request, bin2hex(random_bytes(24)));
            if ($status !== 'disabled') {
                $request->session()->flash('status', VerificationRequests::message($status));
            }
        } catch (\Throwable) {
            $request->session()->flash('status', 'Не удалось принять запрос на письмо. Попробуйте позже.');
        }

        return redirect()->route('verification.notice');
    }

    public function accept(Request $request, string $token, AcceptsInvitations $acceptance): RedirectResponse
    {
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($acceptance, $request, $token): void {
            $membership = $acceptance->accept($request->user(), $token);
            app(AuditWriter::class)->record(new TenantContext($membership->organization_id, actorId: $request->user()->id), new AuditEntry('account.invitation_accepted', 'membership', $membership->id));
        });
        $request->session()->forget('auth.invitation');

        return redirect()->route('auth.ready');
    }
}
