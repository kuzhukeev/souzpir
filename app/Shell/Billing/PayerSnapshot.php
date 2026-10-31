<?php

namespace App\Shell\Billing;

use App\Models\Organization;
use SensitiveParameter;

/** Revision covers all payer fields and changes on every successful modification. */
final class PayerSnapshot
{
    public static function read(#[SensitiveParameter] Organization $organization): array
    {
        $settings = $organization->settings ?? [];
        $stored = $settings['payer'] ?? [];
        $stored = is_array($stored) ? $stored : [];
        $data = [];
        foreach (['legal_name', 'inn', 'kpp', 'address', 'email'] as $field) {
            $data[$field] = is_string($stored[$field] ?? null) ? $stored[$field] : '';
        }

        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new \LogicException('Payer revision unavailable.');
        }
        $payload = json_encode(['organization' => $organization->id, 'payer' => $stored], JSON_THROW_ON_ERROR);

        return ['data' => $data, 'revision' => hash_hmac('sha256', 'billing.payer.v1|'.$payload, $key)];
    }
}
