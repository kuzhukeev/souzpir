<?php

namespace App\Tenancy;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Str;
use LogicException;

final class Cabinet
{
    /** Reserve organization of the current installation; used before any membership exists. */
    public function organizationId(): string
    {
        $id = config('features.E0-02.defaults.organization_id');
        if (! is_string($id) || ! Str::isUlid($id)) {
            throw new LogicException('Configure CABINET_ORGANIZATION_ID before enabling tenant routes.');
        }

        return $id;
    }

    /** A user belongs to at most one organization: its active membership, or the reserve. */
    public function organizationIdFor(User $user): string
    {
        $membership = Membership::withoutGlobalScopes()->where('user_id', $user->id)->where('status', 'active')->first();

        return $membership?->organization_id ?? $this->organizationId();
    }
}
