<?php

namespace App\Shell\Support\Models;

final class TicketAttachment extends SupportModel
{
    protected $hidden = ['storage_path', 'sha256'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
