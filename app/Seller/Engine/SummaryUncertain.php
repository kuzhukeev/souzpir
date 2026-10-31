<?php

namespace App\Seller\Engine;

use RuntimeException;

/** The provider result is unknown and must be reconciled rather than treated as a fallback. */
final class SummaryUncertain extends RuntimeException
{
    public function __construct(#[\SensitiveParameter] public readonly string $aiCallId)
    {
        parent::__construct('Summary call has an uncertain outcome.');
    }
}
