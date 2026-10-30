<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\BrandGrants;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;

class Membership extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['user_id', 'role', 'status', 'brand_ids', 'settings', 'invited_by'];

    protected $attributes = ['role' => 'member', 'status' => 'active', 'brand_ids' => '[]', 'settings' => '{}'];

    protected function casts(): array
    {
        return ['brand_ids' => 'array', 'settings' => 'array'];
    }

    public function tenantUsesBrand(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::saving(function (Membership $membership): void {
            $organizationId = $membership->organization_id ?? app(TenantContext::class)->requireOrganizationId();
            BrandGrants::validate($organizationId, $membership->brand_ids);
            if (! in_array($membership->role, ['owner', 'admin', 'member', 'viewer'], true) || ! in_array($membership->status, ['active', 'disabled'], true)) {
                throw ValidationException::withMessages(['role' => __('Tenancy/Access/messages.invalid_role')]);
            }
            $settings = $membership->settings ?? [];
            if (! is_array($settings)) {
                throw ValidationException::withMessages(['settings' => __('Tenancy/Access/messages.invalid_manager')]);
            }
            $manager = $settings['sales_manager'] ?? false;
            $membership->settings = ['sales_manager' => false, ...$settings];
            if (! is_bool($manager) || ($manager && $membership->role !== 'member')) {
                throw ValidationException::withMessages(['settings.sales_manager' => __('Tenancy/Access/messages.invalid_manager')]);
            }
            if ($membership->exists && ($membership->isDirty('user_id') || $membership->isDirty('organization_id'))) {
                throw new LogicException('Membership identity cannot change.');
            }
            if ($membership->exists && ($membership->role !== 'owner' || $membership->status !== 'active')) {
                $membership->assertNotDesignatedOwner();
            }
        });
        static::deleting(fn (Membership $membership) => $membership->assertNotDesignatedOwner());
    }

    private function assertNotDesignatedOwner(): void
    {
        if (Organization::query()->whereKey($this->organization_id)->where('owner_user_id', $this->user_id)->exists()) {
            throw new LogicException('Transfer the designated owner atomically before removing their membership.');
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permitsBrand(string $brandId): bool
    {
        if ($this->status !== 'active' || ! in_array($this->role, ['owner', 'admin', 'member', 'viewer'], true)) {
            return false;
        }
        $ids = $this->brand_ids ?? [];

        return (in_array($this->role, ['owner', 'admin'], true) && $ids === []) || in_array($brandId, $ids, true);
    }

    /** Coarse mutation guard only. E0-03 owns per-action permission policy. */
    public function canWrite(): bool
    {
        return $this->status === 'active' && in_array($this->role, ['owner', 'admin', 'member'], true);
    }
}
