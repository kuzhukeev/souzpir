<?php

namespace App\Seller\Conversations\Models;

final class SellerTurn extends SellerModel
{
    protected $hidden = ['claim_hash', 'request_hash'];

    protected function casts(): array
    {
        return ['outcome' => 'array', 'base_version' => 'integer'];
    }
}
