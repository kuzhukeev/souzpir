<?php

namespace App\Shadow;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ShadowComparison extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $guarded = ['organization_id', 'brand_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Shadow comparisons are append-only.'));
        self::deleting(fn () => throw new LogicException('Shadow comparisons are append-only.'));
    }
}
