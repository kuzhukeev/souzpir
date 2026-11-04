<?php

namespace App\Seller\Runtime\Contracts;

final readonly class DeliveryReceipt
{
    public function __construct(public string $status, public string $messageId) {}
}
