<?php

namespace App\Shell\Support\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class SupportModel extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    public function tenantUsesBrand(): bool
    {
        return false;
    }
}
