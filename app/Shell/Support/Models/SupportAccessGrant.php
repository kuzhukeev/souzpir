<?php

namespace App\Shell\Support\Models;

final class SupportAccessGrant extends SupportModel
{
    protected function casts(): array
    {
        return ['granted_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
