<?php

namespace App\Seller\Engine;

use RuntimeException;

/** Provider/accounting result is uncertain; operator reconciliation is required. */
final class ExtractionUncertain extends RuntimeException
{
    public function __construct(public readonly string $aiCallId, public readonly string $reason)
    {
        parent::__construct('Extractor call has an uncertain outcome.');
    }
}
