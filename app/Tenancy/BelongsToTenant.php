<?php

namespace App\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    /** Brand-owned roots opt in by default; organization-only models explicitly override. */
    public function tenantUsesBrand(): bool
    {
        return true;
    }

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $context = app(TenantContext::class);
            $model = $query->getModel();
            if ($context->organizationId === null || ($model->tenantUsesBrand() && $context->brandId === null)) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->where($model->qualifyColumn('organization_id'), $context->organizationId);
            if ($model->tenantUsesBrand()) {
                $query->where($model->qualifyColumn('brand_id'), $context->brandId);
            }
        });
        static::creating(function ($model): void {
            $context = app(TenantContext::class);
            $organizationId = $context->requireOrganizationId();
            if ($model->organization_id !== null && $model->organization_id !== $organizationId) {
                throw new LogicException('Organization must match the context.');
            }
            $model->organization_id = $organizationId;
            if ($model->tenantUsesBrand()) {
                if ($context->brandId === null || ($model->brand_id !== null && $model->brand_id !== $context->brandId)) {
                    throw new LogicException('Brand must match the context.');
                }
                $model->brand_id = $context->brandId;
            }
        });
        static::updating(fn ($model) => $model->assertTenantWrite());
        static::deleting(fn ($model) => $model->assertTenantWrite());
    }

    private function assertTenantWrite(): void
    {
        $context = app(TenantContext::class);
        if ($context->organizationId === null || $this->organization_id !== $context->organizationId || $this->isDirty('organization_id')) {
            throw new LogicException('Organization cannot change or cross context.');
        }
        if ($this->tenantUsesBrand() && ($context->brandId === null || $this->brand_id !== $context->brandId || $this->isDirty('brand_id'))) {
            throw new LogicException('Brand cannot change or cross context.');
        }
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
