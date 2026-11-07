<?php

namespace App\Seller\Runtime;

use App\Seller\Settings\Schedule\AssignmentCandidates;
use App\Seller\Settings\Schedule\Availability;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        return $this->mode($tenant, $settings, $now) !== 'off';
    }

    public function mode(TenantContext $tenant, array $settings, CarbonImmutable $now): string
    {
        $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone');

        return app(Availability::class)->forTime($settings, $now, is_string($timezone) && $timezone !== '' ? $timezone : 'UTC')['mode'];
    }

    public function assignWaiting(TenantContext $tenant, string $conversationId, array $settings): void
    {
        DB::transaction(function () use ($tenant, $conversationId, $settings): void {
            $row = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('id', $conversationId)->where('mode', 'manager')->whereNull('assigned_user_id')->first(['id']);
            if (! $row) {
                return;
            }
            $assignment = is_array($settings['assignment'] ?? null) ? $settings['assignment'] : [];
            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
            $rotation = DB::table('seller_assignment_rotation')->where($scope)->first(['id', 'last_user_id', 'created_at']);
            $candidate = app(AssignmentCandidates::class)->select($tenant, $assignment, $rotation?->last_user_id);
            if ($candidate === null) {
                return;
            }
            $changed = DB::table('conversations')->where($scope)->where('id', $conversationId)->where('mode', 'manager')->whereNull('assigned_user_id')
                ->update(['assigned_user_id' => $candidate, 'updated_at' => now()]);
            if ($changed) {
                if (($assignment['method'] ?? null) === 'round_robin') {
                    DB::table('seller_assignment_rotation')->updateOrInsert($scope, ['id' => $rotation?->id ?? (string) Str::ulid(),
                        'last_user_id' => $candidate, 'created_at' => $rotation?->created_at ?? now(), 'updated_at' => now()]);
                }
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.dialog.assigned', 'conversation', $conversationId, after: ['assigned_user_id' => $candidate]));
            }
        });
    }
}
