<?php

use App\Tenancy\Invitations\AcceptsInvitations;
use App\Tenancy\Invitations\InvitationAcceptance;
use App\Tenancy\ResolveTenant;
use App\Tenancy\TenancySharedProps;
use App\Tenancy\TenantContext;
use App\Tenancy\TokenApi\RequireAbility;
use App\Tenancy\TokenApi\ResolveTokenTenant;
use Database\Seeders\Tenancy\CabinetFixtureSeeder;

return [
    'scoped_bindings' => [TenantContext::class => TenantContext::class],
    'bindings' => [AcceptsInvitations::class => InvitationAcceptance::class],
    'middleware_aliases' => ['tenant' => ResolveTenant::class, 'tenant.token' => ResolveTokenTenant::class, 'token.ability' => RequireAbility::class],
    'shared_props' => [TenancySharedProps::class],
    'seeders' => [CabinetFixtureSeeder::class],
    'defaults' => ['organization_id' => env('CABINET_ORGANIZATION_ID')],
];
