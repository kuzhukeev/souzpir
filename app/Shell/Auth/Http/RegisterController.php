<?php

namespace App\Shell\Auth\Http;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\VerificationRequests;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RegisterController
{
    public function create(): Response
    {
        return Inertia::render('Shell/Auth/Register');
    }

    public function store(Request $request, SessionController $session): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $configured = config('features.E1-01.defaults.bootstrap_token_hash');
        $code = $request->input('bootstrap_code');
        if (! is_string($configured) || ! preg_match('/^[a-f0-9]{64}$/D', $configured) || ! is_string($code) || strlen($code) < 32 || strlen($code) > 256 || ! hash_equals($configured, hash('sha256', $code))) {
            return back()->withErrors(['bootstrap_code' => 'Код создания кабинета недействителен.'])->withInput($request->except(['bootstrap_code', 'password', 'password_confirmation']));
        }
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'phone' => ['nullable', 'regex:/^\+?[0-9 ()-]{7,25}$/'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'terms' => ['accepted'], 'ai_consent' => ['accepted'], 'marketing' => ['boolean'], 'bootstrap_code' => ['required', 'string', 'min:32', 'max:256'],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except(['bootstrap_code', 'password', 'password_confirmation']));
        }
        $data = $validator->validated();
        $organizationId = app(Cabinet::class)->organizationId();
        try {
            $user = DB::transaction(function () use ($data, $organizationId): User {
                $organization = Organization::whereKey($organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
                if ($organization->owner_user_id || Membership::withoutGlobalScopes()->where('organization_id', $organizationId)->exists()) {
                    throw ValidationException::withMessages(['bootstrap_code' => 'Регистрация владельца уже завершена. Для входа в команду используйте приглашение.']);
                }
                $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
                $user->forceFill(['phone' => $data['phone'] ?? null, 'terms_accepted_at' => now(), 'ai_consent_at' => now(), 'marketing_opt_in' => (bool) ($data['marketing'] ?? false)])->save();
                $context = new TenantContext($organizationId, actorId: $user->id);
                app(TenantContextRunner::class)->run($context, function () use ($user, $organization, $context): void {
                    Membership::create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
                    $organization->forceFill(['owner_user_id' => $user->id])->save();
                    app(AuditWriter::class)->record($context, new AuditEntry('account.owner_registered', 'user', $user->id));
                });

                return $user;
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($request->except(['bootstrap_code', 'password', 'password_confirmation']));
        }
        $session->complete($request, $user, false);
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
}
