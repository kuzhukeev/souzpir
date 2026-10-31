<?php

namespace App\ProtectedOperations;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\Contracts\ProtectedOperations\SessionTransactionalIdempotency;
use App\Shell\Auth\AuthSessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class PostgresSessionIdempotency implements SessionTransactionalIdempotency
{
    public function execute(
        #[\SensitiveParameter] Request $request,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] string $canonicalRequest,
        #[\SensitiveParameter] Closure $write,
    ): array {
        $conflict = new IdempotencyConflict;
        try {
            return $this->executeInternal($request, $operation, $key, $canonicalRequest, $write, $conflict);
        } catch (Throwable $error) {
            if ($error === $conflict) {
                throw new IdempotencyConflict('Idempotency key was used with different content.');
            }

            throw new IdempotencyFailure('Idempotent operation could not be completed.');
        }
    }

    private function executeInternal(
        #[\SensitiveParameter] Request $request,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] string $canonicalRequest,
        #[\SensitiveParameter] Closure $write,
        #[\SensitiveParameter] IdempotencyConflict $conflict,
    ): array {
        $secret = config('app.key');
        if (! preg_match('/\A[a-z][a-z0-9_.-]{0,79}\z/D', $operation)
            || $key === '' || strlen($key) > 512 || strlen($canonicalRequest) > 1_048_576
            || ! is_string($secret) || $secret === '' || DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Invalid session idempotency input.');
        }
        $keyDigest = hash_hmac('sha256', "protected-session-key-v1\0".$key, $secret);
        $requestDigest = hash_hmac('sha256', "protected-session-request-v1\0".$canonicalRequest, $secret);
        $keyFingerprint = hash_hmac('sha256', 'protected-operation-key-fingerprint-v1', $secret);

        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $operation, $keyDigest, $requestDigest, $keyFingerprint, $secret, $write, $conflict): array {
            $sessionHash = hash_hmac('sha256', 'auth-session:'.$request->session()->getId(), $secret);
            DB::statement('INSERT INTO protected_operation_key_guard (id, fingerprint) VALUES (1, ?) ON CONFLICT (id) DO NOTHING', [$keyFingerprint]);
            $guard = DB::table('protected_operation_key_guard')->where('id', 1)->sharedLock()->first();
            if (! $guard || ! hash_equals($guard->fingerprint, $keyFingerprint)) {
                throw new LogicException('Idempotency application key changed; migration is required.');
            }
            $lease = DB::table('c02_session_leases')->where('session_hash', $sessionHash)->lockForUpdate()->first();
            if (! $lease) {
                // withinActive holds the registry row, serializing creation for
                // this session. The database permanently reserves the new ID.
                DB::table('c02_session_leases')->insert(['id' => (string) Str::ulid(), 'session_hash' => $sessionHash]);
                $lease = DB::table('c02_session_leases')->where('session_hash', $sessionHash)->lockForUpdate()->first();
            }
            if (! $lease) {
                throw new LogicException('Session lease is missing.');
            }
            $created = DB::affectingStatement(
                'INSERT INTO c02_session_receipts (id, lease_id, operation, key_digest, request_digest) VALUES (?, ?, ?, ?, ?) ON CONFLICT (lease_id, operation, key_digest) DO NOTHING',
                [(string) Str::ulid(), $lease->id, $operation, $keyDigest, $requestDigest],
            );
            $row = DB::table('c02_session_receipts')->where('lease_id', $lease->id)->where('operation', $operation)
                ->where('key_digest', $keyDigest)->lockForUpdate()->first();
            if (! $row) {
                throw new LogicException('Session receipt is missing.');
            }
            if (! hash_equals($row->request_digest, $requestDigest)) {
                throw $conflict;
            }
            if ($created === 0) {
                if (! is_string($row->result_ciphertext)) {
                    throw new LogicException('Idempotent operation is already in progress.');
                }
                $stored = json_decode(Crypt::decryptString($row->result_ciphertext), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($stored) || ($stored['receipt'] ?? null) !== $row->id || ($stored['lease'] ?? null) !== $lease->id
                    || ($stored['operation'] ?? null) !== $operation || ($stored['key_digest'] ?? null) !== $keyDigest
                    || ($stored['request_digest'] ?? null) !== $requestDigest || ! is_array($stored['result'] ?? null)) {
                    throw new LogicException('Session receipt binding is invalid.');
                }

                return $stored['result'];
            }
            $result = $write();
            if (! is_array($result)) {
                throw new LogicException('Idempotent result must be an array.');
            }
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($json) > 1_048_576) {
                throw new LogicException('Idempotent result is too large.');
            }
            $payload = json_encode([
                'receipt' => $row->id, 'lease' => $lease->id, 'operation' => $operation,
                'key_digest' => $keyDigest, 'request_digest' => $requestDigest,
                'result' => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            DB::table('c02_session_receipts')->where('id', $row->id)->update(['result_ciphertext' => Crypt::encryptString($payload), 'updated_at' => now()]);

            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        });
    }
}
