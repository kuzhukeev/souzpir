<?php

namespace App\Shell\Support\Contracts;

final readonly class PlanRequestResult
{
    /** deliveryStatus describes support delivery, not the cabinet notification. */
    public function __construct(public string $ticketId, public bool $created, public PlanRequestDeliveryStatus $deliveryStatus) {}
}
