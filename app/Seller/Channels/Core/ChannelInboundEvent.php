<?php

namespace App\Seller\Channels\Core;

use App\Seller\Conversations\Models\SellerModel;

final class ChannelInboundEvent extends SellerModel
{
    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
