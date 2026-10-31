<?php

namespace App\Shell\Auth\Http;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\EligibleAccount;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordController
{
    public function request(): Response
    {
        return Inertia::render('Shell/Auth/ForgotPassword', ['mailConfigured' => (bool) config('features.E1-01.defaults.verification_enabled'), 'status' => session('status')]);
    }

    public function email(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        if (config('features.E1-01.defaults.verification_enabled') && app(EligibleAccount::class)->includes($data['email'])) {
            try {
                Password::sendResetLink($data);
            } catch (\Throwable) { /* Do not expose account existence or credentials. */
            }
        }

        return back()->with('status', 'Если адрес зарегистрирован, инструкции будут отправлены.');
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Shell/Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validator = Validator::make($request->all(), ['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(12)]]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except(['token', 'password', 'password_confirmation']));
        }
        $data = $validator->validated();
        $changed = DB::transaction(function () use ($data): bool {
            $token = DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            if (! $token || ! $user || ! app(EligibleAccount::class)->includes($data['email']) || ! $token->created_at || Carbon::parse($token->created_at)->addMinutes((int) config('auth.passwords.users.expire', 60))->lessThanOrEqualTo(now()) || ! Hash::check($data['token'], $token->token)) {
                return false;
            }
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
            app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationId(), actorId: $user->id), new AuditEntry('account.password.reset', 'user', $user->id));
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));

            return true;
        });
        if (! $changed) {
            return back()->withErrors(['email' => 'Ссылка недействительна или истекла. Запросите новую.'])->withInput($request->except(['token', 'password', 'password_confirmation']));
        }

        return redirect()->route('login')->with('status', 'Пароль обновлён. Войдите снова.');
    }
}
