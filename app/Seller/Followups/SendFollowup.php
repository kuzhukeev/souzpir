<?php

namespace App\Seller\Followups;

use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendFollowup implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $organizationId, public string $brandId, public string $candidateId) {}

    public function handle(FollowupSender $sender, FakeChannelDelivery $delivery, FollowupPolicy $policy): void
    {
        $sender->send(new TenantContext($this->organizationId, $this->brandId, source: 'job'), $this->candidateId, $delivery, $policy);
    }
}
