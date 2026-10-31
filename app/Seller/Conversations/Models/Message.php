<?php

namespace App\Seller\Conversations\Models;

final class Message extends SellerModel
{
    protected function casts(): array
    {
        return ['meta' => 'array', 'delivered_at' => 'datetime'];
    }
}
