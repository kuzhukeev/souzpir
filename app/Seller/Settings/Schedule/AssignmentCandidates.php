<?php

namespace App\Seller\Settings\Schedule;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AssignmentCandidates
{
    /** Filters by per-member channel/hours only when the caller supplies $channel/$now/$timezone. */
    public function select(TenantContext $tenant, array $settings, ?string $last = null, ?string $channel = null, ?CarbonImmutable $now = null, ?string $timezone = null): ?string
    {
        $eligible = [];
        foreach ($settings['members'] ?? [] as $entry) {
            if (! ($entry['enabled'] ?? false) || ! is_string($entry['id'] ?? null)) {
                continue;
            }
            if (! $this->matchesChannel($entry, $channel) || ! $this->withinHours($entry, $now, $timezone)) {
                continue;
            }
            $member = Membership::withoutGlobalScopes()->with('user')->where('organization_id', $tenant->organizationId)->where('user_id', $entry['id'])->where('status', 'active')->first();
            if (! $member || ! $member->user || ! $member->permitsBrand($tenant->brandId)) {
                continue;
            }
            $actor = new TenantContext($tenant->organizationId, $tenant->brandId, $member->user_id, $member->id);
            if (! app(TenantContextRunner::class)->run($actor, fn () => Gate::forUser($member->user)->allows('seller.dialogues.operate'))) {
                continue;
            }
            $active = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('assigned_user_id', $member->user_id)->whereNotIn('stage', ['done', 'lost', 'spam'])->count();
            if (($entry['max_active'] ?? null) === null || $active < (int) $entry['max_active']) {
                $eligible[] = $member->user_id;
            }
        }

        return app(AssignmentSelector::class)->select($settings['method'] ?? 'first_taken', $eligible, $last, $settings['fixed'] ?? null);
    }

    private function matchesChannel(array $entry, ?string $channel): bool
    {
        $channels = $entry['channels'] ?? [];
        if ($channel === null || ! is_array($channels) || $channels === []) {
            return true;
        }

        return in_array($channel, $channels, true);
    }

    private function withinHours(array $entry, ?CarbonImmutable $now, ?string $timezone): bool
    {
        $hours = $entry['hours'] ?? null;
        if ($now === null || $timezone === null || ! is_array($hours) || ! is_string($hours['start'] ?? null) || ! is_string($hours['end'] ?? null)) {
            return true;
        }
        $time = $now->setTimezone($timezone)->format('H:i');

        return $time >= $hours['start'] && $time < $hours['end'];
    }
}
