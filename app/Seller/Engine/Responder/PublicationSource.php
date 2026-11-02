<?php

namespace App\Seller\Engine\Responder;

use App\Tenancy\TenantContext;

/** Loads the published snapshot and responder prompt for the current tenant. */
interface PublicationSource
{
    public function load(TenantContext $tenant): array;
}
