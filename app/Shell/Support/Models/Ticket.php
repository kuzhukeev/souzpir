<?php

namespace App\Shell\Support\Models;

final class Ticket extends SupportModel
{
    protected $attributes = ['type' => 'support', 'status' => 'open', 'delivery_status' => 'pending'];
}
