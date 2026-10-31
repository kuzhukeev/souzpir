<?php

namespace App\Seller\Engine;

use RuntimeException;

/** Worker must wait for the existing call; no fallback, turn commit or new paid call. */
final class ExtractionPending extends RuntimeException
{
    public function __construct(public readonly string $aiCallId)
    {
        parent::__construct('Extractor call is still in progress.');
    }
}
