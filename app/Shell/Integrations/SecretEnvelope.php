<?php

namespace App\Shell\Integrations;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Authenticate the entire temporary payload even when session encryption is disabled. */
final class SecretEnvelope
{
    public function seal(Request $request, TenantContext $context, string $type, #[SensitiveParameter] string $value): string
    {
        try {
            if (DB::transactionLevel() < 1) {
                throw new RuntimeException('A transaction is required.');
            }
            $nonce = bin2hex(random_bytes(32));
            $sealed = Crypt::encryptString(json_encode([
                'type' => $type,
                'nonce' => $nonce,
                'organization_id' => $context->organizationId,
                'brand_id' => $context->brandId,
                'actor_id' => $context->actorId,
                'session_hash' => $this->sessionHash($request),
                'value' => $value,
            ], JSON_THROW_ON_ERROR));
            DB::table('integration_secret_receipts')->insert([
                'nonce_hash' => hash('sha256', $nonce),
                'expires_at' => now()->addMinutes(max(1, (int) config('session.lifetime', 120))),
            ]);

            return $sealed;
        } catch (Throwable) {
            throw new RuntimeException('Unable to protect integration secret.');
        }
    }

    public function open(Request $request, TenantContext $context, string $type, mixed $sealed): ?array
    {
        if (! is_string($sealed) || $context->organizationId === null || $context->brandId === null || $context->actorId === null) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($sealed), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['type'] ?? null) !== $type
                || ($data['organization_id'] ?? null) !== $context->organizationId
                || ($data['brand_id'] ?? null) !== $context->brandId
                || ($data['actor_id'] ?? null) !== $context->actorId
                || ! is_string($data['nonce'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $data['nonce']) !== 1
                || ! is_string($data['session_hash'] ?? null)
                || ! hash_equals($this->sessionHash($request), $data['session_hash'])
                || ! is_string($data['value'] ?? null) || $data['value'] === '') {
                return null;
            }

            return ['value' => $data['value'], 'nonce_hash' => hash('sha256', $data['nonce'])];
        } catch (Throwable) {
            return null;
        }
    }

    private function sessionHash(Request $request): string
    {
        return hash_hmac('sha256', 'integration-secret:'.$request->session()->getId(), (string) config('app.key'));
    }
}
