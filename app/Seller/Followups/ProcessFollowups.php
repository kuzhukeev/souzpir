<?php

namespace App\Seller\Followups;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ProcessFollowups implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $organizationId, public string $brandId) {}

    public function handle(FollowupPolicy $policy): void
    {
        (new PrepareFollowups($this->organizationId, $this->brandId))->handle($policy);
        DB::table('seller_followup_candidates')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)
            ->where('status', 'pending')->orderBy('created_at')->each(function ($candidate): void {
                SendFollowup::dispatch($this->organizationId, $this->brandId, $candidate->id);
            });
    }
}
