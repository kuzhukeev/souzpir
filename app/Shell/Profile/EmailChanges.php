<?php

namespace App\Shell\Profile;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SecureAccountContext;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class EmailChanges
{
    public function available(): bool
    {
        if (! app(ProfileSettingsAvailability::class)->ready() || ! SecureAccountContext::canonicalEmailAllowed() || ! config('features.E1-08.defaults.email_change_enabled', false)) {
            return false;
        }

        // Keep live mail disabled until an operator explicitly enables delivery.
        return app()->environment('testing') && ! (app(EmailChangeSender::class) instanceof MailEmailChangeSender);
    }

    public function pending(User $user): ?string
    {
        $row = DB::table('profile_email_changes')->where('user_id', $user->id)->where('expires_at', '>', now())->whereIn('status', ['pending', 'sending', 'sent', 'uncertain'])->first();

        return $row && $row->auth_version === $user->auth_version && hash_equals($row->source_fingerprint, $this->fingerprint($user->email)) && $row->target_email
            ? Crypt::decryptString($row->target_email) : null;
    }

    public function request(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] string $email, #[\SensitiveParameter] string $password): void
    {
        try {
            app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($email, $password): void {
                if (! Hash::check($password, $user->password)) {
                    throw ValidationException::withMessages(['current_password' => 'Неверный текущий пароль.']);
                }
                if (! $this->available()) {
                    throw ValidationException::withMessages(['email' => 'Почтовый сервис пока не подключён.']);
                }
                if ($email === mb_strtolower($user->email) || DB::table('users')->whereRaw('lower(email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['email' => 'Этот адрес нельзя использовать.']);
                }
                // Expired reservations must not permanently occupy an address.
                DB::table('profile_email_changes')->where('target_fingerprint', $this->fingerprint($email))->where('expires_at', '<=', now())->update($this->cleared('expired'));
                $reserved = DB::table('profile_email_changes')->where('target_fingerprint', $this->fingerprint($email))->where('user_id', '!=', $user->id)->exists();
                if ($reserved) {
                    throw ValidationException::withMessages(['email' => 'Этот адрес нельзя использовать.']);
                }
                $id = (string) Str::ulid();
                $token = bin2hex(random_bytes(32));
                DB::table('profile_email_changes')->updateOrInsert(['user_id' => $user->id], [
                    'id' => $id, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                    'target_email' => Crypt::encryptString($email), 'target_fingerprint' => $this->fingerprint($email),
                    'source_fingerprint' => $this->fingerprint($user->email), 'auth_version' => $user->auth_version,
                    'token_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token),
                    'status' => 'pending', 'expires_at' => now()->addMinutes(60), 'created_at' => now(), 'updated_at' => now(),
                ]);
                app(AuditWriter::class)->record($context, new AuditEntry('profile.email.requested', 'user', $user->id));
                DB::afterCommit(function () use ($id): void {
                    try {
                        Bus::dispatch((new DeliverEmailChange($id))->onQueue(config('runtime.queues.notifications'))->afterCommit());
                    } catch (Throwable) {
                        // The pending intent remains durable; no uncertain send is automatically retried.
                    }
                });
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                throw ValidationException::withMessages(['email' => 'Этот адрес нельзя использовать.']);
            }
            throw new \RuntimeException('Email change processing failed.');
        }
    }

    /** A durable intent is claimed once; uncertain sends are never retried automatically. */
    public function deliver(string $id): void
    {
        if (! app(ProfileSettingsAvailability::class)->ready()) {
            throw new LogicException('Profile email delivery is disabled or not ready.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Email delivery must run outside a database transaction.');
        }
        $snapshot = DB::table('profile_email_changes')->where('id', $id)->first();
        if (! $snapshot || $snapshot->status !== 'pending' || ! $this->available()) {
            return;
        }
        $claimed = DB::transaction(function () use ($snapshot): ?object {
            $user = User::whereKey($snapshot->user_id)->lock('for no key update')->first();
            $org = DB::table('organizations')->where('id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $brand = DB::table('brands')->where('id', $snapshot->brand_id)->where('organization_id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $member = Membership::withoutGlobalScopes()->where('organization_id', $snapshot->organization_id)->where('user_id', $snapshot->user_id)->where('status', 'active')->lockForUpdate()->first();
            $row = DB::table('profile_email_changes')->where('id', $snapshot->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending') {
                return null;
            }
            if (! $user || ! $org || ! $brand || ! $member || ! $member->permitsBrand($row->brand_id) || $row->auth_version !== $user->auth_version || ! hash_equals($row->source_fingerprint, $this->fingerprint($user->email)) || now()->greaterThanOrEqualTo($row->expires_at)) {
                DB::table('profile_email_changes')->where('id', $row->id)->update($this->cleared('cancelled'));

                return null;
            }
            DB::table('profile_email_changes')->where('id', $row->id)->update(['status' => 'sending', 'updated_at' => now()]);

            return $row;
        });
        if (! $claimed) {
            return;
        }
        try {
            $base = rtrim((string) config('app.url'), '/');
            $parts = parse_url($base);
            if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new LogicException('Invalid canonical application URL.');
            }
            $url = $base.URL::temporarySignedRoute('profile.email.confirm', CarbonImmutable::parse($claimed->expires_at), ['change' => $claimed->id], absolute: false).'#token='.Crypt::decryptString($claimed->encrypted_token);
            $sent = app(EmailChangeSender::class)->send(Crypt::decryptString($claimed->target_email), $url);
            $status = $sent ? 'sent' : 'uncertain';
        } catch (Throwable) {
            $status = 'uncertain';
        }
        DB::table('profile_email_changes')->where('id', $id)->where('status', 'sending')->update(['status' => $status, 'encrypted_token' => null, 'updated_at' => now()]);
    }

    public function validate(User $user, TenantContext $context, string $id, #[\SensitiveParameter] string $token): object
    {
        abort_unless($this->available(), 404);
        $row = DB::table('profile_email_changes')->where('id', $id)->where('user_id', $user->id)
            ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->lockForUpdate()->first();
        abort_unless($row && in_array($row->status, ['pending', 'sending', 'sent', 'uncertain'], true) && $row->token_hash && hash_equals($row->token_hash, hash('sha256', $token)) && $row->target_email && $row->auth_version === $user->auth_version && hash_equals($row->source_fingerprint, $this->fingerprint($user->email)) && now()->lessThan($row->expires_at), 404);

        return $row;
    }

    public function preview(User $user, TenantContext $context, string $id): void
    {
        abort_unless($this->available(), 404);
        $row = DB::table('profile_email_changes')->where('id', $id)->where('user_id', $user->id)
            ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->first();
        abort_unless($row && in_array($row->status, ['pending', 'sending', 'sent', 'uncertain'], true)
            && $row->token_hash && $row->target_email && $row->auth_version === $user->auth_version
            && hash_equals($row->source_fingerprint, $this->fingerprint($user->email))
            && now()->lessThan($row->expires_at), 404);
    }

    public function confirm(#[\SensitiveParameter] Request $request, string $id, #[\SensitiveParameter] string $token): void
    {
        $version = null;
        try {
            app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($request, $id, $token, &$version): void {
                $row = $this->validate($user, $context, $id, $token);
                $email = Crypt::decryptString($row->target_email);
                if (DB::table('users')->whereRaw('lower(email) = ?', [$email])->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'Этот адрес нельзя использовать.']);
                }
                $version = $user->auth_version + 1;
                $user->forceFill(['email' => $email, 'email_verified_at' => now(), 'auth_version' => $version, 'remember_token' => Str::random(60)])->save();
                app(AuthSessionRegistry::class)->advanceVersion($request, $version);
                DB::table('profile_email_changes')->where('id', $id)->update($this->cleared('confirmed'));
                DB::table('password_reset_tokens')->where('email', $request->user()->email)->delete();
                app(AuditWriter::class)->record($context, new AuditEntry('profile.email.changed', 'user', $user->id));
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                throw ValidationException::withMessages(['email' => 'Этот адрес нельзя использовать.']);
            }
            throw new \RuntimeException('Email confirmation processing failed.');
        }
        $request->session()->put('auth.version', $version);
        $request->user()->refresh();
    }

    public function cancel(#[\SensitiveParameter] Request $request): bool
    {
        try {
            return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context): bool {
                $cancelled = DB::table('profile_email_changes')->where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'sending', 'sent', 'uncertain'])
                    ->update($this->cleared('cancelled'));
                if ($cancelled === 0) {
                    return false;
                }
                app(AuditWriter::class)->record($context, new AuditEntry('profile.email.cancelled', 'user', $user->id));

                return true;
            });
        } catch (AuthenticationException|ValidationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            throw new \RuntimeException('Email cancellation processing failed.');
        }
    }

    private function fingerprint(#[\SensitiveParameter] string $email): string
    {
        return hash_hmac('sha256', 'profile-email:'.mb_strtolower($email), (string) config('app.key'));
    }

    private function cleared(string $status): array
    {
        return ['status' => $status, 'target_email' => null, 'target_fingerprint' => null, 'token_hash' => null, 'encrypted_token' => null, 'updated_at' => now()];
    }
}
