<?php

namespace App\Seller\Conversations\Models;

final class Contact extends SellerModel
{
    protected function casts(): array
    {
        return ['external_ids' => 'array'];
    }
}
