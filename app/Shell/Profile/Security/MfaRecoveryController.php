<?php

namespace App\Shell\Profile\Security;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\MfaCode;
use App\Shell\Auth\MfaRecoveryTransport;
use App\Shell\Auth\MfaSettingsThrottle;
use App\Shell\Auth\SecureAccountContext;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MfaRecoveryController
{
    public function localPage(#[\SensitiveParameter] Request $request): Response
    {
        abort_unless(MfaRecoveryTransport::local($request), 404);
        app(ProfileAccess::class)->run($request, function (User $user): void {
            abort_unless($user->mfa_confirmed_at && $user->mfa_secret, 403);
        });
        $nonce = base64_encode(random_bytes(18));

        return response()->view('shell.profile.mfa-recovery-local', ['nonce' => $nonce])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    }

    public function issue(#[\SensitiveParameter] Request $request): JsonResponse
    {
        app(MfaSettingsThrottle::class)->hit($request);
        if (! MfaRecoveryTransport::allows($request)) {
            throw ValidationException::withMessages(['code' => SecureAccountContext::MESSAGE]);
        }
        $safe = $request->attributes->get('mfa.safe_code');
        if (! is_array($safe) || ($safe['kind'] ?? null) !== 'totp' || ! is_string($safe['digest'] ?? null)) {
            throw ValidationException::withMessages(['code' => 'Введите действующий код из приложения.']);
        }
        try {
            [$user, $codes] = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($request, $safe): array {
                if (! $user->mfa_confirmed_at || ! $user->mfa_secret) {
                    throw ValidationException::withMessages(['code' => 'Сначала включите двухфакторную проверку.']);
                }
                $counter = MfaCode::matchTotp($user->mfa_secret, $safe['digest'], $user->mfa_last_counter);
                if ($counter === null) {
                    throw ValidationException::withMessages(['code' => 'Неверный код.']);
                }
                $user->forceFill(['mfa_last_counter' => $counter, 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
                app(AuthSessionRegistry::class)->advanceVersion($request, $user->auth_version);
                DB::table('mfa_recovery_codes')->where('user_id', $user->id)->delete();
                $codes = [];
                for ($i = 0; $i < 10; $i++) {
                    $raw = bin2hex(random_bytes(16));
                    DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id,
                        'code_hash' => MfaCode::digest($raw), 'created_at' => now(), 'updated_at' => now()]);
                    $codes[] = strtoupper(implode('-', str_split($raw, 8)));
                }
                app(AuditWriter::class)->record($context, new AuditEntry('account.mfa.recovery_codes_rotated', 'user', $user->id));

                return [$user, $codes];
            });
        } catch (ValidationException|AuthenticationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            throw new \RuntimeException('MFA recovery generation failed.');
        }
        Auth::guard()->setUser($user);
        $request->session()->put('auth.version', $user->auth_version);

        return response()->json(['codes' => $codes])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
