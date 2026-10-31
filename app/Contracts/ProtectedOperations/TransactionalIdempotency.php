<?php

namespace App\Contracts\ProtectedOperations;

use Closure;

/**
 * Internal DB-only primitive, not an authorization or provenance boundary.
 * The caller must hold appropriate rights and provide canonical request bytes.
 * The callback may use only the same DB connection, with no external effects.
 * Results are provisional until every enclosing transaction has committed.
 */
interface TransactionalIdempotency
{
    /** @param Closure(): array $write */
    public function execute(
        #[\SensitiveParameter] string $organizationId,
        #[\SensitiveParameter] string $brandId,
        string $operation,
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] string $canonicalRequest,
        #[\SensitiveParameter] Closure $write,
    ): array;
}
