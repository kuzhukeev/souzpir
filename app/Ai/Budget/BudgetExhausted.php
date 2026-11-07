<?php

namespace App\Ai\Budget;

use LogicException;

final class BudgetExhausted extends LogicException
{
    public function __construct(public readonly string $level)
    {
        parent::__construct('AI budget exhausted.');
    }
}
