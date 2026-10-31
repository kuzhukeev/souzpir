<?php

use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;

/** Only explicitly marked Laravel Data objects belong to the public frontend contract. */
final class OptInDataTransformer extends DataClassTransformer
{
    protected function shouldTransform(PhpClassNode $phpClassNode): bool
    {
        return parent::shouldTransform($phpClassNode) && count($phpClassNode->getAttributes(TypeScript::class)) > 0;
    }
}
