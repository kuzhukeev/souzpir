<?php

namespace App\Data\Shared;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ModulesData extends Data
{
    public function __construct(public ?bool $seller, public ?bool $vision) {}
}
