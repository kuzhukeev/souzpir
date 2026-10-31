<?php

namespace App\ProtectedOperations;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\Contracts\ProtectedOperations\TransactionalIdempotency;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class PostgresTransactionalIdempotency implements TransactionalIdempotency
{
    public function execute(
        #[\SensitiveParameter] string $organizationId,
        #[\SensitiveParameter] string $brandId,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] string $canonicalRequest,
        #[\SensitiveParameter] Closure $write,
    ): array {
        // Only this private sentinel represents our own verified conflict. A
        // callback may throw the same public type with sensitive text.
        $conflict = new IdempotencyConflict;
        try {
            return $this->executeInternal($organizationId, $brandId, $operation, $key, $canonicalRequest, $write, $conflict);
        } catch (\Throwable $error) {
            if ($error === $conflict) {
                throw new IdempotencyConflict('Idempotency key was used with different content.');
            }

            throw new IdempotencyFailure('Idempotent operation could not be completed.');
        }
    }

    private function executeInternal(
        #[\SensitiveParameter] string $organizationId,
        #[\SensitiveParameter] string $brandId,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] string $canonicalRequest,
        #[\SensitiveParameter] Closure $write,
        #[\SensitiveParameter] IdempotencyConflict $conflict,
    ): array {
        if (! Str::isUlid($organizationId) || ! Str::isUlid($brandId)
            || ! preg_match('/\A[a-z][a-z0-9_.-]{0,79}\z/D', $operation)
            || $key === '' || strlen($key) > 512 || strlen($canonicalRequest) > 1_048_576) {
            throw new LogicException('Invalid idempotency input.');
        }
        $secret = config('app.key');
        if (! is_string($secret) || $secret === '' || DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('PostgreSQL and an application key are required.');
        }
        $keyDigest = hash_hmac('sha256', "protected-operation-key-v1\0".$key, $secret);
        $requestDigest = hash_hmac('sha256', "protected-operation-request-v1\0".$canonicalRequest, $secret);
        $keyFingerprint = hash_hmac('sha256', 'protected-operation-key-fingerprint-v1', $secret);

        return DB::transaction(function () use ($organizationId, $brandId, $operation, $keyDigest, $requestDigest, $keyFingerprint, $write, $conflict): array {
            DB::statement('INSERT INTO protected_operation_key_guard (id, fingerprint) VALUES (1, ?) ON CONFLICT (id) DO NOTHING', [$keyFingerprint]);
            $guard = DB::table('protected_operation_key_guard')->where('id', 1)->sharedLock()->first();
            if (! $guard || ! hash_equals($guard->fingerprint, $keyFingerprint)) {
                throw new LogicException('Idempotency application key changed; migration is required.');
            }
            $created = DB::affectingStatement(
                'INSERT INTO protected_operation_receipts (id, organization_id, brand_id, operation, key_digest, request_digest, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (organization_id, brand_id, operation, key_digest) DO NOTHING',
                [(string) Str::ulid(), $organizationId, $brandId, $operation, $keyDigest, $requestDigest, now(), now()],
            );
            $row = DB::table('protected_operation_receipts')->where('organization_id', $organizationId)->where('brand_id', $brandId)
                ->where('operation', $operation)->where('key_digest', $keyDigest)->lockForUpdate()->first();
            if (! $row) {
                throw new LogicException('Idempotency receipt is missing.');
            }
            if (! hash_equals($row->request_digest, $requestDigest)) {
                throw $conflict;
            }
            if ($created === 0) {
                if (! is_string($row->result_ciphertext)) {
                    throw new LogicException('Idempotent operation is already in progress.');
                }

                return json_decode(Crypt::decryptString($row->result_ciphertext), true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $write();
            if (! is_array($result)) {
                throw new LogicException('Idempotent result must be an array.');
            }
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($json) > 1_048_576) {
                throw new LogicException('Idempotent result is too large.');
            }
            DB::table('protected_operation_receipts')->where('id', $row->id)->update([
                'result_ciphertext' => Crypt::encryptString($json),
                'updated_at' => now(),
            ]);

            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        });
    }
}
