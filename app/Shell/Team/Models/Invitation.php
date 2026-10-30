<?php

namespace App\Shell\Team\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\BrandGrants;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Invitation extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['email', 'role', 'brand_ids', 'settings', 'token_hash', 'invited_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected $attributes = ['brand_ids' => '[]', 'settings' => '{}'];

    protected function casts(): array
    {
        return ['brand_ids' => 'array', 'settings' => 'array', 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function tenantUsesBrand(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::updating(function (Invitation $invitation): void {
            if ($invitation->isDirty(['email', 'role', 'brand_ids', 'settings', 'token_hash', 'invited_by', 'expires_at'])) {
                throw new \LogicException('Issue a new invitation instead of changing its signed grant.');
            }
            foreach (['accepted_at', 'accepted_by', 'revoked_at'] as $field) {
                if ($invitation->getRawOriginal($field) !== null && $invitation->isDirty($field)) {
                    throw new \LogicException('Consumed or revoked invitation state is immutable.');
                }
            }
        });
        static::saving(function (Invitation $invitation): void {
            $invitation->email = mb_strtolower(trim($invitation->email));
            $organizationId = $invitation->organization_id ?? app(TenantContext::class)->requireOrganizationId();
            BrandGrants::validate($organizationId, $invitation->brand_ids);
            $settings = $invitation->settings ?? [];
            if (! is_array($settings)) {
                throw ValidationException::withMessages(['settings' => __('Tenancy/Access/messages.invalid_invitation')]);
            }
            $manager = $settings['sales_manager'] ?? false;
            $invitation->settings = ['sales_manager' => false, ...$settings];
            if (! filter_var($invitation->email, FILTER_VALIDATE_EMAIL) || ! in_array($invitation->role, ['admin', 'member', 'viewer'], true) || ! is_bool($manager) || ($manager && $invitation->role !== 'member')) {
                throw ValidationException::withMessages(['invitation' => __('Tenancy/Access/messages.invalid_invitation')]);
            }
        });
    }
}
