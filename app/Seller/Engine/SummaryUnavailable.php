<?php

namespace App\Seller\Engine;

use RuntimeException;

/** A confirmed but unusable result cannot become a completed summary. */
final class SummaryUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Summary output is unavailable.');
    }
}
