<?php

namespace App\Seller\Conversations\Models;

final class SellerTask extends SellerModel
{
    use ValidatesAssignee;

    protected function casts(): array
    {
        return ['payload' => 'array', 'due_at' => 'datetime'];
    }
}
