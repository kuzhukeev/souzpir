<?php

namespace App\Vision\Site;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class BrandAchievement extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'organization_id', 'brand_id'];
}
