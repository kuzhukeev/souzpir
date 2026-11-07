<?php

namespace App\Shell\Auth\Http;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\PasswordDelivery\PasswordEligibility;
use App\Shell\Auth\PasswordDelivery\PasswordRecoveryTransport;
use App\Shell\Auth\PasswordDelivery\PasswordRequests;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordController
{
    public function request(): Response
    {
        return Inertia::render('Shell/Auth/ForgotPassword', ['mailConfigured' => app(PasswordRequests::class)->available(), 'status' => session('status')]);
    }

    public function email(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        try {
            app(PasswordRequests::class)->request($data['email']);
        } catch (\Throwable) { /* Preserve the same response and do not log identifiers. */
        }

        return redirect()->route('password.request')->with('status', 'Если адрес зарегистрирован, инструкции будут отправлены.');
    }

    public function open(Request $request): HttpResponse
    {
        if (! PasswordRecoveryTransport::allows($request)) {
            return response('Для восстановления откройте защищённый адрес.', 403)
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }
        $request->session()->forget('auth.password_recovery');
        $nonce = base64_encode(random_bytes(18));
        $csrf = json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $html = <<<'HTML'
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Восстановление пароля</title></head><body><main><p id="status">Открываем ссылку…</p><p id="retry" hidden><a href="/forgot-password">Запросить новую ссылку</a></p><noscript>Для открытия ссылки включите JavaScript.</noscript></main><script nonce="__NONCE__">
(()=>{
    const status=document.getElementById('status');
    const retry=document.getElementById('retry');
    const failed='Ссылка недействительна. Запросите новую ссылку для восстановления.';
    let generation=0, queue=Promise.resolve(), uncertain=false;
    function capture(provided){
        const fragment=typeof provided==='string'?provided:location.hash.slice(1);
        if(!fragment)return;
        if(typeof provided!=='string')history.replaceState(null,'',location.pathname);
        const version=++generation;
        const params=new URLSearchParams(fragment);
        const token=params.get('token')||'',email=params.get('email')||'';
        const valid=[...params.keys()].length===2&&params.getAll('token').length===1&&params.getAll('email').length===1&&/^[a-f0-9]{64}$/.test(token)&&email.length>0&&email.length<=254;
        status.textContent='Открываем ссылку…';
        retry.hidden=true;
        queue=queue.then(async()=>{
            if(version!==generation)return;
            if(uncertain)throw 0;
            let response;
            try{
                response=await fetch('/reset-password/exchange',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':__CSRF__},body:JSON.stringify(valid?{token,email}:{}),redirect:'error',cache:'no-store'});
            }catch{uncertain=true;throw 0;}
            if(location.hash)capture();
            if(version!==generation)return;
            if(!response.ok)throw 0;
            location.replace('/reset-password');
        }).catch(()=>{if(location.hash)capture();if(version===generation){status.textContent=failed;retry.hidden=false;}});
    }
    if(window.navigation)navigation.addEventListener('navigate',event=>{
        if(!event.hashChange||!event.cancelable||event.navigationType==='traverse')return;
        const destination=new URL(event.destination.url);
        if(destination.origin!==location.origin||destination.pathname!==location.pathname||!destination.hash)return;
        event.preventDefault();capture(destination.hash.slice(1));
    });
    window.addEventListener('hashchange',()=>capture());
    if(location.hash)capture();else{status.textContent=failed;retry.hidden=false;}
})();
</script></body></html>
HTML;

        return response(str_replace(['__NONCE__', '__CSRF__'], [$nonce, $csrf], $html))
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'")
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function exchange(#[\SensitiveParameter] Request $request): JsonResponse
    {
        try {
            $request->session()->forget('auth.password_recovery');
            if (! PasswordRecoveryTransport::allows($request)) {
                return response()->json(['ok' => false], 403);
            }
            $hash = $request->attributes->get('password.reset_token_hash');
            $encryptedEmail = $request->attributes->get('password.reset_email_encrypted');
            if (! is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 || ! is_string($encryptedEmail) || $encryptedEmail === '') {
                return response()->json(['ok' => false], 404);
            }
            $request->session()->migrate(true);
            $request->session()->put('auth.password_recovery', ['hash' => $hash, 'email' => $encryptedEmail]);

            return response()->json(['ok' => true]);
        } catch (\Throwable) {
            return response()->json(['ok' => false], 404);
        } finally {
            $request->request->remove('token');
            $request->request->remove('email');
            $request->json()->remove('token');
            $request->json()->remove('email');
        }
    }

    public function reset(Request $request): Response|HttpResponse
    {
        if (! PasswordRecoveryTransport::allows($request)) {
            return response('Для восстановления откройте защищённый адрес.', 403)
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }

        return Inertia::render('Shell/Auth/ResetPassword', ['hasLink' => $request->session()->has('auth.password_recovery')]);
    }

    public function store(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        if (! PasswordRecoveryTransport::allows($request)) {
            return redirect()->route('password.request')->withErrors(['email' => 'Для восстановления откройте защищённый адрес.']);
        }
        $input = $request->only(['password', 'password_confirmation']);
        $validator = Validator::make($input, [
            'password' => ['required', PasswordRule::min(12)],
            'password_confirmation' => ['required', 'same:password'],
        ], ['password_confirmation.same' => 'Пароли не совпадают']);
        if ($validator->fails()) {
            return $this->resetErrorRedirect($request)->withErrors($validator);
        }
        $grant = $request->session()->get('auth.password_recovery');
        try {
            if (! is_array($grant) || ! is_string($grant['hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $grant['hash']) !== 1 || ! is_string($grant['email'] ?? null)) {
                throw new \RuntimeException;
            }
            $email = Crypt::decryptString($grant['email']);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            $request->session()->forget('auth.password_recovery');

            return $this->resetErrorRedirect($request)->withErrors(['email' => 'Ссылка недействительна или истекла. Запросите новую.']);
        }
        $data = [...$validator->validated(), 'email' => $email, 'token_hash' => $grant['hash']];
        try {
            $changed = DB::transaction(function () use ($data): bool {
                $user = User::where('email', $data['email'])->lockForUpdate()->first();
                $eligible = app(PasswordEligibility::class)->lock($user);
                $token = DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();
                if (! $token || ! $user || ! $eligible || $token->auth_version === null || (int) $token->auth_version !== $user->auth_version || ! $token->created_at || Carbon::parse($token->created_at)->addMinutes((int) config('auth.passwords.users.expire', 60))->lessThanOrEqualTo(now()) || ! Hash::check('v2:'.$data['token_hash'], $token->token)) {
                    return false;
                }
                $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
                app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationIdFor($user), actorId: $user->id), new AuditEntry('account.password.reset', 'user', $user->id));
                DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                event(new PasswordReset($user));

                return true;
            });
        } catch (\Throwable) {
            // Do not propagate query bindings or a stack containing credentials.
            throw new \RuntimeException('Password reset processing failed.');
        }
        if (! $changed) {
            return $this->resetErrorRedirect($request)->withErrors(['email' => 'Ссылка недействительна или истекла. Запросите новую.']);
        }

        $request->session()->forget('auth.password_recovery');

        return redirect()->route('password.changed');
    }

    public function changed(): Response
    {
        return Inertia::render('Shell/Auth/PasswordChanged');
    }

    /** Never trust Referer when returning a form containing a recovery token. */
    private function resetErrorRedirect(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        return (new RedirectResponse(route('password.reset', absolute: false)))->setRequest($request)->setSession($request->session());
    }
}
