<?php

namespace App\Seller\Conversations\Models;

final class MessageReview extends SellerModel
{
    use ValidatesMemberReference;

    protected function casts(): array
    {
        return [];
    }
}
