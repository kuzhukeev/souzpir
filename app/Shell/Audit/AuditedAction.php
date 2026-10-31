<?php

namespace App\Shell\Audit;

use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

trait AuditedAction
{
    protected function audited(TenantContext $context, Closure $mutation, Closure $entry): mixed
    {
        return DB::transaction(function () use ($context, $mutation, $entry): mixed {
            $result = $mutation();
            app(AuditWriter::class)->record($context, $entry($result));

            return $result;
        });
    }
}
