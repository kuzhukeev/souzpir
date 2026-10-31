<?php

namespace App\Shell\Auth\PasswordDelivery;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class PasswordDelivery
{
    /** Do not disclose whether the email identifies an account. */
    public function enqueue(#[\SensitiveParameter] string $email): ?string
    {
        try {
            return $this->enqueueInternal($email);
        } catch (Throwable) {
            throw new \RuntimeException('Password request processing failed.');
        }
    }

    private function enqueueInternal(#[\SensitiveParameter] string $email): ?string
    {
        if (! config('features.E1-01F.defaults.enabled')) {
            return null;
        }

        return DB::transaction(function () use ($email): ?string {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user || ! app(PasswordEligibility::class)->lock($user)) {
                return null;
            }
            $last = DB::table('password_delivery_intents')->where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($last && CarbonImmutable::parse($last->created_at)->addSeconds((int) config('auth.passwords.users.throttle', 60))->greaterThan(now())) {
                return null;
            }
            DB::table('password_delivery_intents')->where('user_id', $user->id)->where('status', 'pending')->where('created_at', '<=', now()->subMinutes((int) config('auth.passwords.users.expire', 60)))->update(['status' => 'cancelled', 'reason' => 'expired', 'updated_at' => now()]);
            if (DB::table('password_delivery_intents')->where('user_id', $user->id)->whereIn('status', ['pending', 'sending', 'transmitting'])->exists()) {
                return null;
            }
            $id = (string) Str::ulid();
            DB::table('password_delivery_intents')->insert(['id' => $id, 'user_id' => $user->id, 'email_fingerprint' => $this->emailFingerprint($user), 'auth_version' => $user->auth_version, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationId(), actorId: $user->id), new AuditEntry('account.password.requested', 'user', $user->id));

            return $id;
        });
    }

    public function claim(string $id): ?DeliveryClaim
    {
        try {
            return $this->claimInternal($id);
        } catch (Throwable) {
            throw new \RuntimeException('Password delivery processing failed.');
        }
    }

    private function claimInternal(string $id): ?DeliveryClaim
    {
        $this->outsideTransaction();
        $token = bin2hex(random_bytes(32));
        $claimed = $this->withLockedState($id, function ($intent, $user, bool $eligible) use ($token): bool {
            if ($intent->status !== 'pending') {
                return false;
            }
            if (! $this->permitted($intent, $user, $eligible)) {
                $this->transition($intent->id, 'pending', 'cancelled', 'access_revoked');

                return false;
            }

            return DB::table('password_delivery_intents')->where('id', $intent->id)->where('status', 'pending')->update(['status' => 'sending', 'claim_hash' => hash('sha256', $token), 'updated_at' => now()]) === 1;
        });

        return $claimed ? new DeliveryClaim($id, $token) : null;
    }

    /** The raw reset token exists only in worker memory; transport runs outside all transactions. */
    public function send(#[\SensitiveParameter] DeliveryClaim $claim, #[\SensitiveParameter] PasswordTransport $transport): void
    {
        try {
            $this->sendInternal($claim, $transport);
        } catch (Throwable) {
            throw new \RuntimeException('Password delivery processing failed.');
        }
    }

    private function sendInternal(#[\SensitiveParameter] DeliveryClaim $claim, #[\SensitiveParameter] PasswordTransport $transport): void
    {
        $this->outsideTransaction();
        $token = bin2hex(random_bytes(32));
        $user = $this->withLockedState($claim->id, function ($intent, $user, bool $eligible) use ($claim, $token) {
            if ($intent->status !== 'sending' || ! is_string($intent->claim_hash) || ! hash_equals($intent->claim_hash, $claim->tokenHash())) {
                return null;
            }
            if (! $this->permitted($intent, $user, $eligible)) {
                $this->transition($intent->id, 'sending', 'cancelled', 'access_revoked');

                return null;
            }
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make($token), 'auth_version' => $user->auth_version, 'created_at' => now()]);
            DB::table('password_delivery_intents')->where('id', $intent->id)->update(['status' => 'transmitting', 'updated_at' => now()]);

            return $user;
        });
        if (! $user) {
            return;
        }
        try {
            $accepted = $transport->send($user, $token);
            $status = $accepted ? 'sent' : 'uncertain';
            $reason = $accepted ? null : 'transport_uncertain';
        } catch (KnownDeliveryFailure) {
            $status = 'failed';
            $reason = 'known_failure';
        } catch (Throwable) {
            $status = 'uncertain';
            $reason = 'transport_uncertain';
        }
        DB::table('password_delivery_intents')->where('id', $claim->id)->where('status', 'transmitting')->where('claim_hash', $claim->tokenHash())->update(['status' => $status, 'reason' => $reason, 'claim_hash' => null, 'updated_at' => now()]);
    }

    /** An interrupted send may have reached the provider: never resend it automatically. */
    public function markUncertain(string $id): bool
    {
        $this->outsideTransaction();

        return DB::table('password_delivery_intents')->where('id', $id)->whereIn('status', ['sending', 'transmitting'])->update(['status' => 'uncertain', 'reason' => 'worker_interrupted', 'claim_hash' => null, 'updated_at' => now()]) === 1;
    }

    private function withLockedState(string $id, callable $action): mixed
    {
        $ref = DB::table('password_delivery_intents')->where('id', $id)->first(['user_id']);
        if (! $ref) {
            return null;
        }

        return DB::transaction(function () use ($id, $ref, $action) {
            $user = User::whereKey($ref->user_id)->lockForUpdate()->first();
            $eligible = app(PasswordEligibility::class)->lock($user);
            $intent = DB::table('password_delivery_intents')->where('id', $id)->where('user_id', $ref->user_id)->lockForUpdate()->first();

            return $intent ? $action($intent, $user, $eligible) : null;
        });
    }

    private function permitted(object $intent, ?User $user, bool $eligible): bool
    {
        return (bool) config('features.E1-01F.defaults.enabled') && $user && $eligible && (int) $intent->auth_version === $user->auth_version && hash_equals($intent->email_fingerprint, $this->emailFingerprint($user)) && CarbonImmutable::parse($intent->created_at)->addMinutes((int) config('auth.passwords.users.expire', 60))->greaterThan(now());
    }

    private function emailFingerprint(#[\SensitiveParameter] User $user): string
    {
        return hash_hmac('sha256', 'password-email:'.$user->email, (string) config('app.key'));
    }

    private function transition(string $id, string $from, string $to, string $reason): void
    {
        DB::table('password_delivery_intents')->where('id', $id)->where('status', $from)->update(['status' => $to, 'reason' => $reason, 'claim_hash' => null, 'updated_at' => now()]);
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Delivery must run outside a database transaction.');
        }
    }
}
