<?php

namespace App\Seller\Runtime;

use App\Seller\Settings\Schedule\AssignmentCandidates;
use App\Seller\Settings\Schedule\Availability;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BrandRuntimeSchedule
{
    public function settings(TenantContext $tenant): array
    {
        $raw = DB::table('seller_schedule_preferences')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('payload');
        $settings = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($settings) ? $settings : [];
    }

    public function available(TenantContext $tenant, array $settings, CarbonImmutable $now): bool
    {
        $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone');

        return app(Availability::class)->forTime($settings, $now, is_string($timezone) && $timezone !== '' ? $timezone : 'UTC')['mode'] !== 'off';
    }

    public function assignWaiting(TenantContext $tenant, string $conversationId, array $settings): void
    {
        $assignment = is_array($settings['assignment'] ?? null) ? $settings['assignment'] : [];
        $candidate = app(AssignmentCandidates::class)->select($tenant, $assignment);
        if ($candidate === null) {
            return;
        }
        $changed = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('id', $conversationId)->where('mode', 'manager')->whereNull('assigned_user_id')
            ->update(['assigned_user_id' => $candidate, 'updated_at' => now()]);
        if ($changed) {
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.dialog.assigned', 'conversation', $conversationId, after: ['assigned_user_id' => $candidate]));
        }
    }
}
