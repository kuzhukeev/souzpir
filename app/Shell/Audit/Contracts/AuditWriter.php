<?php

namespace App\Shell\Audit\Contracts;

use App\Shell\Audit\AuditEntry;
use App\Tenancy\TenantContext;

interface AuditWriter
{
    public function record(TenantContext $context, AuditEntry $entry): void;
}
