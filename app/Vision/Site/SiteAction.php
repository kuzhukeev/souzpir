<?php

namespace App\Vision\Site;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SiteAction extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'actions';

    protected $guarded = ['id', 'organization_id', 'brand_id'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'completed_at' => 'date:Y-m-d'];
    }
}
