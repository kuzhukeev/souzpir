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

    /** A user belongs to one organization: the reserve one when they are a member there, otherwise their active membership, otherwise the reserve. */
    public function organizationIdFor(User $user): string
    {
        $organizations = Membership::withoutGlobalScopes()->where('user_id', $user->id)->where('status', 'active')
            ->orderBy('created_at')->orderBy('id')->pluck('organization_id');
        $reserve = config('features.E0-02.defaults.organization_id');
        if (is_string($reserve) && $organizations->contains($reserve)) {
            return $reserve;
        }

        return $organizations->first() ?? $this->organizationId();
    }
}
