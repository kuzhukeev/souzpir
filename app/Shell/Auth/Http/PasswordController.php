<?php

namespace App\Shell\Auth\Http;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\PasswordDelivery\PasswordEligibility;
use App\Shell\Auth\PasswordDelivery\PasswordRequests;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function reset(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] string $token): Response
    {
        return Inertia::render('Shell/Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function store(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validator = Validator::make($request->all(), ['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(12)]]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except(['token', 'password', 'password_confirmation']));
        }
        $data = $validator->validated();
        try {
            $changed = DB::transaction(function () use ($data): bool {
                $user = User::where('email', $data['email'])->lockForUpdate()->first();
                $eligible = app(PasswordEligibility::class)->lock($user);
                $token = DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();
                if (! $token || ! $user || ! $eligible || $token->auth_version === null || (int) $token->auth_version !== $user->auth_version || ! $token->created_at || Carbon::parse($token->created_at)->addMinutes((int) config('auth.passwords.users.expire', 60))->lessThanOrEqualTo(now()) || ! Hash::check($data['token'], $token->token)) {
                    return false;
                }
                $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
                app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationId(), actorId: $user->id), new AuditEntry('account.password.reset', 'user', $user->id));
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
            return back()->withErrors(['email' => 'Ссылка недействительна или истекла. Запросите новую.'])->withInput($request->except(['token', 'password', 'password_confirmation']));
        }

        return redirect()->route('login')->with('status', 'Пароль обновлён. Войдите снова.');
    }
}
