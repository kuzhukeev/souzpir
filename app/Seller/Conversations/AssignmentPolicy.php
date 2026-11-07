<?php

namespace App\Seller\Conversations;

use Illuminate\Support\Facades\DB;
use LogicException;

/** The SQL policy is authoritative for both Eloquent and direct SQL writes. */
final class AssignmentPolicy
{
    public static function assertConfiguration(): void
    {
        $roles = config('permissions.abilities', [])['seller.dialogs.write'] ?? null;
        $databaseRoles = json_decode((string) DB::scalar('SELECT public.seller_assignment_roles()::text'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($roles) || ! is_array($databaseRoles) || count($roles) !== count($databaseRoles)
            || array_diff($roles, $databaseRoles) || array_diff($databaseRoles, $roles)) {
            throw new LogicException('Seller assignment configuration does not match its database policy.');
        }
    }
}
