<?php

namespace App\Seller\Dialogs;

use App\Tenancy\TenantContext;

interface MessageTemplates
{
    public function forBrand(TenantContext $context): array;
}
