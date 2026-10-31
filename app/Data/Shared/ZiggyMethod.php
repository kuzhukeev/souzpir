<?php

namespace App\Data\Shared;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ZiggyMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Patch = 'PATCH';
    case Put = 'PUT';
    case Options = 'OPTIONS';
    case Delete = 'DELETE';
}
