<?php

namespace App\Seller\Runtime;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
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
    /** Channel keys accepted for a per-channel mode override; must match resources/js Schedule CHANNELS. */
    public const CHANNELS = ['widget', 'telegram', 'api', 'email', 'whatsapp'];

    public function settings(TenantContext $tenant): array
    {
        $raw = DB::table('seller_schedule_preferences')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('payload');
        $settings = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($settings) ? $settings : [];
    }

    public function available(TenantContext $tenant, array $settings, CarbonImmutable $now, ?string $channel = null): bool
    {
        return $this->mode($tenant, $settings, $now, $channel) !== 'off';
    }

    /** Runtime contract: callers that know the inbound channel (widget/telegram/api/email/whatsapp)
     * should pass it so a configured channel override can take priority over the module-wide mode. */
    public function mode(TenantContext $tenant, array $settings, CarbonImmutable $now, ?string $channel = null): string
    {
        $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone');
        $override = $channel !== null ? $this->channelOverride($settings, $channel) : null;

        return app(Availability::class)->forTime($settings, $now, is_string($timezone) && $timezone !== '' ? $timezone : 'UTC', $override)['mode'];
    }

    private function channelOverride(array $settings, string $channel): ?string
    {
        $mode = $settings['channel_overrides'][$channel] ?? null;

        return in_array($mode, ['autonomous', 'suggest', 'off'], true) ? $mode : null;
    }

    /** Runtime contract: the phrase a suppressed/handed-off turn should show the client when the
     * dialog is passed to a manager (schedule off-hours or tariff-limit exhaustion handoff). */
    public function fallbackPhrase(TenantContext $tenant): string
    {
        try {
            $persona = app(KnowledgeRepository::class)->published($tenant)->persona();

            return app(ServicePhraseSelector::class)->forEvent($persona, 'handoff') ?? ServicePhraseSelector::DEFAULTS['handoff'];
        } catch (KnowledgeNotReady) {
            return ServicePhraseSelector::DEFAULTS['handoff'];
        }
    }

    public function assignWaiting(TenantContext $tenant, string $conversationId, array $settings): void
    {
        DB::transaction(function () use ($tenant, $conversationId, $settings): void {
            $row = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('id', $conversationId)->where('mode', 'manager')->whereNull('assigned_user_id')->first(['id', 'channel_account_id']);
            if (! $row) {
                return;
            }
            $assignment = is_array($settings['assignment'] ?? null) ? $settings['assignment'] : [];
            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
            $rotation = DB::table('seller_assignment_rotation')->where($scope)->first(['id', 'last_user_id', 'created_at']);
            $channel = $row->channel_account_id ? DB::table('channel_accounts')->where('id', $row->channel_account_id)->value('type') : null;
            $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone');
            $candidate = app(AssignmentCandidates::class)->select($tenant, $assignment, $rotation?->last_user_id, is_string($channel) ? $channel : null,
                CarbonImmutable::now(), is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');
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
