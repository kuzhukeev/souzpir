<?php

namespace App\Seller\Engine;

use RuntimeException;

/** An existing call is still running; it must not be converted to a summary or retried. */
final class SummaryPending extends RuntimeException
{
    public function __construct(#[\SensitiveParameter] public readonly string $aiCallId)
    {
        parent::__construct('Summary call is still in progress.');
    }
}
