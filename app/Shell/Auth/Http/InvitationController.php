<?php

namespace App\Shell\Auth\Http;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\InvitationExchangeGrants;
use App\Shell\Auth\VerificationRequests;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Cabinet;
use App\Tenancy\Invitations\InvitationAcceptance;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class InvitationController
{
    private function invite(Request $request): Invitation
    {
        $id = app(InvitationExchangeGrants::class)->invitationId($request);
        abort_unless(is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/iD', $id), 404);
        $org = app(Cabinet::class)->organizationId();
        abort_unless(Organization::whereKey($org)->where('status', 'active')->exists(), 404);

        return Invitation::withoutGlobalScopes()->where('organization_id', $org)->whereKey($id)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->firstOrFail();
    }

    public function open(Request $request): HttpResponse
    {
        $nonce = base64_encode(random_bytes(18));
        $begin = fn () => app(InvitationExchangeGrants::class)->beginDocument($request);
        $epoch = json_encode($request->user() ? app(AuthSessionRegistry::class)->withinActive($request, $begin) : $begin(), JSON_THROW_ON_ERROR);
        $csrf = json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $html = <<<'HTML'
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Приглашение</title></head><body><main><p id="status">Проверяем приглашение…</p><noscript>Для открытия приглашения включите JavaScript.</noscript></main><script nonce="__NONCE__">
(()=>{
    const status=document.getElementById('status');
    const failed='Не удалось открыть приглашение. Запросите новую ссылку.';
    let generation=0;
    let queue=Promise.resolve();
    let uncertain=false;
    function capture(provided){
        const token=typeof provided==='string'?provided:location.hash.slice(1);
        if(!token)return;
        if(typeof provided!=='string')history.replaceState(null,'',location.pathname);
        const version=++generation;
        status.textContent='Проверяем приглашение…';
        // Wait for each server response before starting the next exchange: both
        // requests modify the same session and an older commit must not win.
        queue=queue.then(async()=>{
            if(version!==generation)return;
            if(uncertain)throw 0;
            // Invalid fragments still clear an older session grant, without sending
            // arbitrary fragment content to the server.
            const candidate=/^[A-Za-z0-9]{64}$/.test(token)?token:'';
            let response;
            try{
                response=await fetch('/invitations/exchange',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':__CSRF_VALUE__},body:JSON.stringify({token:candidate,epoch:__EPOCH_VALUE__,sequence:version}),redirect:'error',cache:'no-store'});
            }catch{
                // A failed transport can hide a server commit that is still in flight.
                // Do not issue another exchange from this page in that case.
                uncertain=true;
                throw 0;
            }
            // hashchange is queued as a task; a response may resolve first.
            if(location.hash)capture();
            if(version!==generation)return;
            if(!response.ok){
                const data=await response.json().catch(()=>({}));
                if(['expired','revoked','accepted','email_mismatch'].includes(data.state)){
                    location.replace('/invitations/state?state='+encodeURIComponent(data.state));
                    return;
                }
                throw 0;
            }
            location.replace('/invitations/show');
        }).catch(()=>{
            if(location.hash)capture();
            if(version===generation)status.textContent=failed;
        });
    }
    if(window.navigation)navigation.addEventListener('navigate',event=>{
        if(!event.hashChange||!event.cancelable||event.navigationType==='traverse')return;
        const destination=new URL(event.destination.url);
        if(destination.origin!==location.origin||destination.pathname!==location.pathname||!destination.hash)return;
        event.preventDefault();
        capture(destination.hash.slice(1));
    });
    window.addEventListener('hashchange',()=>capture());
    if(location.hash)capture();else status.textContent=failed;
})();
</script></body></html>
HTML;

        return response(str_replace(['__NONCE__', '__CSRF_VALUE__', '__EPOCH_VALUE__'], [$nonce, $csrf, $epoch], $html))->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'")
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /** Never throw from this boundary: exception traces may contain Request body arguments. */
    public function exchange(#[\SensitiveParameter] Request $request): JsonResponse
    {
        try {
            $hash = $request->attributes->get('invitation.exchange_hash');
            $epoch = $request->attributes->get('invitation.document_epoch');
            $sequence = $request->attributes->get('invitation.sequence');
            $exchange = fn () => app(InvitationExchangeGrants::class)->exchange($request, $hash, $epoch, $sequence);
            $ok = $request->user() ? app(AuthSessionRegistry::class)->withinActive($request, $exchange) : $exchange();
            if (! $ok) {
                $state = null;
                if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    $row = DB::table('invitations')->where('organization_id', app(Cabinet::class)->organizationId())
                        ->where('token_hash', $hash)->first(['email', 'accepted_at', 'revoked_at', 'expires_at']);
                    $state = $row?->accepted_at ? 'accepted' : ($row?->revoked_at ? 'revoked'
                        : ($row && now()->greaterThanOrEqualTo($row->expires_at) ? 'expired'
                        : ($row && $request->user() && mb_strtolower(trim($request->user()->email)) !== $row->email ? 'email_mismatch' : null)));
                }

                return response()->json(['ok' => false, 'state' => $state], $state ? 410 : 404);
            }
            if ($request->user()) {
                app(InvitationAcceptance::class)->inspectSession($request->user(), $request);
            }
            $request->session()->put('url.intended', route('invitation.show'));

            return response()->json(['ok' => true]);
        } catch (Throwable) {
            return response()->json(['ok' => false], 404);
        } finally {
            $request->request->remove('token');
            $request->json()->remove('token');
        }
    }

    public function state(Request $request): Response
    {
        $state = $request->query('state');
        abort_unless(in_array($state, ['expired', 'revoked', 'accepted', 'email_mismatch'], true), 404);

        return Inertia::render('Shell/Auth/Invitation', ['state' => $state]);
    }

    public function show(Request $request, InvitationAcceptance $acceptance): Response
    {
        $invitation = $this->invite($request);
        if ($request->user()) {
            $acceptance->inspectSession($request->user(), $request);
        } else {
            $request->session()->put('url.intended', route('invitation.show'));
        }

        $organization = DB::table('organizations')->where('id', $invitation->organization_id)->value('name');
        $inviter = DB::table('users')->where('id', $invitation->invited_by)->value('name');
        $brands = DB::table('brands')->where('organization_id', $invitation->organization_id)
            ->whereIn('id', $invitation->brand_ids ?? [])->pluck('name')->all();

        return Inertia::render('Shell/Auth/Invitation', [
            'email' => $invitation->email, 'role' => $invitation->role,
            'inviter' => $inviter, 'organization' => $organization, 'brands' => $brands,
            'salesManager' => (bool) ($invitation->settings['sales_manager'] ?? false),
            'authenticated' => (bool) $request->user(), 'verified' => (bool) $request->user()?->hasVerifiedEmail(),
        ]);
    }

    public function register(Request $request, InvitationAcceptance $acceptance, SessionController $session): RedirectResponse
    {
        $invite = $this->invite($request);
        $validator = Validator::make($request->all(), ['name' => ['required', 'string', 'max:120'], 'password' => ['required', 'confirmed', Password::min(12)], 'terms' => ['accepted']]);
        if ($validator->fails()) {
            return redirect()->route('invitation.show')->withErrors($validator)->withInput($request->except(['password', 'password_confirmation']));
        }
        $data = $validator->validated();
        $user = DB::transaction(function () use ($invite, $data, $acceptance, $request): User {
            abort_unless(app(InvitationExchangeGrants::class)->invitationId($request, true) === $invite->id, 404);
            abort_unless(Organization::whereKey($invite->organization_id)->where('status', 'active')->lockForUpdate()->first(), 404);
            $locked = Invitation::withoutGlobalScopes()->whereKey($invite->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->accepted_at === null && $locked->revoked_at === null && $locked->expires_at->isFuture(), 404);
            if (User::where('email', $locked->email)->exists()) {
                abort(409, 'Для этого адреса уже есть учётная запись. Войдите, чтобы принять приглашение.');
            }
            $user = User::create(['name' => $data['name'], 'email' => $locked->email, 'password' => $data['password']]);
            $user->forceFill(['terms_accepted_at' => now()])->save();
            $acceptance->inspectSession($user, $request);
            app(AuditWriter::class)->record(new TenantContext($locked->organization_id, actorId: $user->id), new AuditEntry('account.invitation_registered', 'user', $user->id));

            return $user;
        });
        $session->complete($request, $user, false);
        try {
            $status = app(VerificationRequests::class)->request($request, bin2hex(random_bytes(24)));
            if ($status !== 'disabled') {
                $request->session()->flash('status', VerificationRequests::message($status));
            }
        } catch (Throwable) {
            $request->session()->flash('status', 'Не удалось принять запрос на письмо. Попробуйте позже.');
        }

        return redirect()->route('verification.notice');
    }

    public function accept(Request $request, InvitationAcceptance $acceptance): RedirectResponse
    {
        $invite = $this->invite($request);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($acceptance, $request): void {
            $membership = $acceptance->acceptSession($request->user(), $request);
            app(AuditWriter::class)->record(new TenantContext($membership->organization_id, actorId: $request->user()->id), new AuditEntry('account.invitation_accepted', 'membership', $membership->id));
            app(InvitationExchangeGrants::class)->revoke($request);
        });

        return redirect()->route('auth.ready');
    }
}
