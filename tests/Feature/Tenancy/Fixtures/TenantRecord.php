<?php

namespace Tests\Feature\Tenancy\Fixtures;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class TenantRecord extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'tenancy_test_records';

    protected $guarded = [];
}
