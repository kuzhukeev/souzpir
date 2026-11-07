<?php

namespace App\Seller\Channels\Api;

use App\ProtectedOperations\PostgresTransactionalIdempotency;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Persist an explicitly supplied source separately from mutable conversation state. */
final class ApiSourceReceipt
{
    public function save(string $organization, string $brand, string $eventId, string $source): void
    {
        app(PostgresTransactionalIdempotency::class)->execute($organization, $brand, 'seller.api.source', $eventId, $source,
            fn (): array => ['event_id' => $eventId, 'source' => $source]);
    }

    /** @return array<string, string> */
    public function forEvents(string $organization, string $brand, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $secret = config('app.key');
        $digests = array_map(fn (string $id): string => hash_hmac('sha256', "protected-operation-key-v1\0".$id, $secret), $eventIds);
        $rows = DB::table('protected_operation_receipts')->where('organization_id', $organization)->where('brand_id', $brand)
            ->where('operation', 'seller.api.source')->whereIn('key_digest', $digests)->whereNotNull('result_ciphertext')->get(['result_ciphertext']);
        $sources = [];
        foreach ($rows as $row) {
            $value = json_decode(Crypt::decryptString($row->result_ciphertext), true, 512, JSON_THROW_ON_ERROR);
            if (in_array($value['event_id'] ?? null, $eventIds, true) && in_array($value['source'] ?? null, ['site', 'crm'], true)) {
                $sources[$value['event_id']] = $value['source'];
            }
        }

        return $sources;
    }
}
