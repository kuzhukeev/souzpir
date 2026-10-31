<?php

namespace App\Shell\Auth\VerificationDelivery;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class VerificationDelivery
{
    /** Returns status and opaque intent ID. The controller must supply a stable, random key per user action. */
    public function enqueue(Request $request, string $key): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }

        return app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($request, $key): array {
            if ($user->hasVerifiedEmail()) {
                return ['status' => 'verified', 'id' => null];
            }
            if (! $this->lockEligibility($user)) {
                throw new AuthorizationException;
            }
            if (! config('features.E1-01D.defaults.enabled')) {
                return ['status' => 'disabled', 'id' => null];
            }
            $session = $this->fingerprint('auth-session:'.$request->session()->getId());
            $keyHash = $this->fingerprint('verification-key:'.$key);
            $mapping = DB::table('verification_delivery_keys')->where('user_id', $user->id)->where('key_hash', $keyHash)->first();
            if ($mapping) {
                if (! hash_equals($mapping->session_hash, $session)) {
                    return ['status' => 'conflict', 'id' => null];
                }
                $intent = DB::table('verification_delivery_intents')->where('id', $mapping->intent_id)->where('user_id', $user->id)->first();

                return ['status' => $intent->status, 'id' => $intent->id];
            }
            $intent = DB::table('verification_delivery_intents')->where('user_id', $user->id)->whereIn('status', ['pending', 'sending', 'transmitting', 'uncertain'])->first();
            if ($intent && ! hash_equals($intent->session_hash, $session)) {
                return ['status' => 'conflict', 'id' => null];
            }
            if (! $intent) {
                $id = (string) Str::ulid();
                DB::table('verification_delivery_intents')->insert(['id' => $id, 'user_id' => $user->id, 'session_hash' => $session, 'auth_version' => $user->auth_version, 'email_fingerprint' => $this->emailFingerprint($user), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationId(), actorId: $user->id), new AuditEntry('account.verification.requested', 'user', $user->id));
                $intent = DB::table('verification_delivery_intents')->where('id', $id)->first();
            }
            DB::table('verification_delivery_keys')->insert(['user_id' => $user->id, 'key_hash' => $keyHash, 'session_hash' => $session, 'intent_id' => $intent->id, 'created_at' => now(), 'updated_at' => now()]);

            return ['status' => $intent->status, 'id' => $intent->id];
        });
    }

    /** The claim must only be held in worker memory; no outer database transaction is allowed. */
    public function claim(string $id): ?DeliveryClaim
    {
        $this->outsideTransaction();
        $token = bin2hex(random_bytes(32));
        $claimed = $this->withLockedState($id, function ($intent, $session, $user, bool $eligible) use ($token): bool {
            if ($intent->status !== 'pending') {
                return false;
            }
            if (! config('features.E1-01D.defaults.enabled')) {
                $this->transition($intent->id, 'pending', 'failed', 'disabled');

                return false;
            }
            if (! $this->permitted($intent, $session, $user, $eligible)) {
                $this->transition($intent->id, 'pending', 'cancelled', 'access_revoked');

                return false;
            }

            return DB::table('verification_delivery_intents')->where('id', $intent->id)->where('status', 'pending')->update(['status' => 'sending', 'claim_hash' => hash('sha256', $token), 'updated_at' => now()]) === 1;
        });

        return $claimed ? new DeliveryClaim($id, $token) : null;
    }

    /** Recheck immediately before transport. Logout after this check cannot retract an in-flight message. */
    public function send(DeliveryClaim $claim, VerificationTransport $transport): void
    {
        $this->outsideTransaction();
        $user = $this->withLockedState($claim->id, function ($intent, $session, $user, bool $eligible) use ($claim) {
            if ($intent->status !== 'sending' || ! is_string($intent->claim_hash) || ! hash_equals($intent->claim_hash, $claim->tokenHash())) {
                return null;
            }
            if (! config('features.E1-01D.defaults.enabled') || ! $this->permitted($intent, $session, $user, $eligible)) {
                $this->transition($intent->id, 'sending', 'cancelled', 'access_revoked');

                return null;
            }
            DB::table('verification_delivery_intents')->where('id', $intent->id)->where('status', 'sending')->update(['status' => 'transmitting', 'updated_at' => now()]);

            return $user;
        });
        if (! $user) {
            return;
        }
        try {
            $accepted = $transport->send($user);
            $status = $accepted ? 'sent' : 'uncertain';
            $reason = $accepted ? null : 'transport_uncertain';
        } catch (KnownDeliveryFailure) {
            $status = 'failed';
            $reason = 'known_failure';
        } catch (Throwable) {
            $status = 'uncertain';
            $reason = 'transport_uncertain';
        }
        DB::table('verification_delivery_intents')->where('id', $claim->id)->where('status', 'transmitting')->where('claim_hash', $claim->tokenHash())->update(['status' => $status, 'reason' => $reason, 'claim_hash' => null, 'updated_at' => now()]);
    }

    /** Conservative operator recovery; never resends. Safe even if a worker later returns. */
    public function markUncertain(string $id): bool
    {
        $this->outsideTransaction();

        return DB::table('verification_delivery_intents')->where('id', $id)->whereIn('status', ['sending', 'transmitting'])->update(['status' => 'uncertain', 'reason' => 'worker_interrupted', 'claim_hash' => null, 'updated_at' => now()]) === 1;
    }

    private function withLockedState(string $id, callable $action): mixed
    {
        $ref = DB::table('verification_delivery_intents')->where('id', $id)->first(['user_id', 'session_hash']);
        if (! $ref) {
            return null;
        }

        return DB::transaction(function () use ($id, $ref, $action) {
            $session = DB::table('auth_session_registry')->where('session_hash', $ref->session_hash)->lockForUpdate()->first();
            $user = User::whereKey($ref->user_id)->lockForUpdate()->first();
            $eligible = $this->lockEligibility($user);
            $intent = DB::table('verification_delivery_intents')->where('id', $id)->where('user_id', $ref->user_id)->where('session_hash', $ref->session_hash)->lockForUpdate()->first();

            return $intent ? $action($intent, $session, $user, $eligible) : null;
        });
    }

    private function permitted(object $intent, ?object $session, ?User $user, bool $eligible): bool
    {
        if (! $session || ! $user || $session->user_id !== $user->id || $session->revoked_at !== null || CarbonImmutable::parse($session->expires_at)->lessThanOrEqualTo(now()) || (int) $session->auth_version !== $user->auth_version || (int) $intent->auth_version !== $user->auth_version || $user->hasVerifiedEmail() || ! hash_equals($intent->email_fingerprint, $this->emailFingerprint($user))) {
            return false;
        }
        if ($session->remember_fingerprint !== null && (! is_string($user->getRememberToken()) || ! hash_equals($session->remember_fingerprint, $this->fingerprint('auth-remember:'.$user->getRememberToken())))) {
            return false;
        }

        return $eligible;
    }

    /** Lock the organization and all possible access grants until the intent transition commits. */
    private function lockEligibility(?User $user): bool
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Eligibility must be checked in a transaction.');
        }
        if (! $user) {
            return false;
        }
        $orgId = app(Cabinet::class)->organizationId();
        $org = Organization::whereKey($orgId)->lockForUpdate()->first();
        if (! $org || $org->status !== 'active') {
            return false;
        }
        // The same order as invitation acceptance: invitation rows before membership.
        $invitations = DB::table('invitations')->where('organization_id', $orgId)->where('email', $user->email)->orderBy('id')->lockForUpdate()->get();
        $membership = DB::table('memberships')->where('organization_id', $orgId)->where('user_id', $user->id)->lockForUpdate()->first();
        if ($membership && $membership->status === 'active') {
            return true;
        }

        return $invitations->contains(fn ($invitation) => $invitation->accepted_at === null && $invitation->revoked_at === null && CarbonImmutable::parse($invitation->expires_at)->greaterThan(now()));
    }

    private function emailFingerprint(User $user): string
    {
        return $this->fingerprint('verification-email:'.$user->email);
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function transition(string $id, string $from, string $to, string $reason): void
    {
        DB::table('verification_delivery_intents')->where('id', $id)->where('status', $from)->update(['status' => $to, 'reason' => $reason, 'claim_hash' => null, 'updated_at' => now()]);
    }

    private function outsideTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Delivery must run outside a database transaction.');
        }
    }
}
