<?php

namespace App\Seller\Conversations\Models;

final class SellerTask extends SellerModel
{
    use ValidatesMemberReference;

    protected function casts(): array
    {
        return ['payload' => 'array', 'due_at' => 'datetime'];
    }
}
