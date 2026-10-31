<?php

namespace App\Vision\Site;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class AuditRun extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'audit_runs';

    protected $guarded = ['id', 'organization_id', 'brand_id'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
