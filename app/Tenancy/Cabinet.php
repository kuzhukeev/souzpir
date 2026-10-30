<?php

namespace App\Tenancy;

use Illuminate\Support\Str;
use LogicException;

final class Cabinet
{
    public function organizationId(): string
    {
        $id = config('features.E0-02.defaults.organization_id');
        if (! is_string($id) || ! Str::isUlid($id)) {
            throw new LogicException('Configure CABINET_ORGANIZATION_ID before enabling tenant routes.');
        }

        return $id;
    }
}
