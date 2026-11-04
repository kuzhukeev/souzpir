<?php

namespace App\Seller\Runtime\Contracts;

interface ChannelDelivery
{
    public function send(DeliveryCommand $command): DeliveryReceipt;
}
