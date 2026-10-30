<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Organization extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'status', 'owner_user_id', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (Organization $organization): void {
            if ($organization->isDirty('owner_user_id')) {
                if ($organization->owner_user_id === null) {
                    throw new LogicException('A provisioned organization cannot lose its owner.');
                }
                $owner = Membership::withoutGlobalScopes()->where('organization_id', $organization->id)
                    ->where('user_id', $organization->owner_user_id)->where('role', 'owner')->where('status', 'active')->exists();
                if (! $owner) {
                    throw new LogicException('The designated owner must have an active owner membership.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Organization deletion is outside this cabinet.'));
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }
}
