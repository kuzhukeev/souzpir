<?php

namespace App\Shell\Profile;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Stores drafts only; no delivery or scheduling consumes these values. */
final class NotificationTimingDrafts
{
    private const MIGRATION = '2026_11_01_010000_e1_08_create_notification_timing_drafts';

    private const TABLE = 'profile_notification_timing_drafts';

    public function ready(): bool
    {
        try {
            // A savepoint keeps a missing or partially applied schema from poisoning
            // the surrounding profile transaction.
            return DB::transaction(static fn (): bool => Schema::hasTable('migrations')
                && DB::table('migrations')->where('migration', self::MIGRATION)->exists()
                && Schema::hasColumns(self::TABLE, ['organization_id', 'brand_id', 'user_id', 'quiet_start', 'quiet_end', 'digest_time', 'created_at', 'updated_at']));
        } catch (QueryException) {
            return false;
        }
    }

    public function read(TenantContext $context): array
    {
        $row = DB::table(self::TABLE)->where($this->key($context))->first();

        return ['quiet_start' => $row?->quiet_start, 'quiet_end' => $row?->quiet_end, 'digest_time' => $row?->digest_time];
    }

    /** Caller holds ProfileAccess's active session and tenant locks. */
    public function save(TenantContext $context, array $values): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Timing draft save requires the profile transaction.');
        }
        $key = $this->key($context);
        $before = $this->read($context);
        $after = ['quiet_start' => $values['quiet_start'] ?? null, 'quiet_end' => $values['quiet_end'] ?? null, 'digest_time' => $values['digest_time'] ?? null];
        DB::table(self::TABLE)->upsert([[...$key, ...$after, 'created_at' => now(), 'updated_at' => now()]], array_keys($key), ['quiet_start', 'quiet_end', 'digest_time', 'updated_at']);
        app(AuditWriter::class)->record($context, new AuditEntry('profile.notification_timing.draft_updated', 'user', $context->actorId, before: $before, after: $after));
    }

    private function key(TenantContext $context): array
    {
        abort_unless($context->organizationId && $context->brandId && $context->actorId, 403);

        return ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $context->actorId];
    }
}
