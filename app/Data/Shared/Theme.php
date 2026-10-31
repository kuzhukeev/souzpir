<?php

namespace App\Data\Shared;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum Theme: string
{
    case Confectionery = 'confectionery';
    case Pyro = 'pyro';
    case Neutral = 'neutral';
}
