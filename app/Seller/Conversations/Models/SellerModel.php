<?php

namespace App\Seller\Conversations\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class SellerModel extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'organization_id', 'brand_id'];
}
