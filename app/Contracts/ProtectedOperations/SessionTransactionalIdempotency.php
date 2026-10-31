<?php

namespace App\Contracts\ProtectedOperations;

use Closure;
use Illuminate\Http\Request;

/**
 * Internal session-scoped DB-only primitive. The actual session and user are
 * checked and locked for the entire operation; it does not grant business rights.
 * The caller must check business rights under an enclosing transaction on every
 * invocation, including replays: the write callback is skipped on replay.
 * The trusted callback may write only through the same DB connection and must
 * not perform external effects or manage transactions. Results are provisional
 * until every enclosing transaction commits.
 */
interface SessionTransactionalIdempotency
{
    /** @param Closure(): array $write */
    public function execute(
        #[\SensitiveParameter] Request $request,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] string $canonicalRequest,
        #[\SensitiveParameter] Closure $write,
    ): array;
}
