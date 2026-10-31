<?php

namespace App\Data\Shared;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class OrganizationData extends Data
{
    public function __construct(public string $id, public string $name) {}
}
