<?php

namespace App\Seller\Conversations\Data;

enum TaskStatus: string
{
    case New = 'new';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
