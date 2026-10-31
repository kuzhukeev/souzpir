<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Brand extends Model implements AuthenticatableContract
{
    use Authenticatable, BelongsToTenant, HasApiTokens, HasUlids;

    protected $fillable = ['name', 'website', 'industry', 'market', 'language', 'timezone', 'theme', 'status', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('selected_brand', function (Builder $query): void {
            $brandId = app(TenantContext::class)->brandId;
            $brandId === null ? $query->whereRaw('1 = 0') : $query->whereKey($brandId);
        });
        $guard = function (Brand $brand): void {
            $active = app(TenantContext::class)->brandId;
            if ($active !== null && $active !== $brand->id) {
                throw new \LogicException('Brand cannot be changed outside its active context.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function tenantUsesBrand(): bool
    {
        return false;
    }
}
