<?php

namespace App\Seller\Conversations;

use PDOException;

/** SQLSTATE is retained for callers; raw SQL, bindings and driver details are not. */
final class SafePdoException extends PDOException
{
    public function __construct(string $state)
    {
        parent::__construct($state === '40P01' ? 'deadlock detected' : 'Database operation failed.');
        $this->code = $state;
        $this->errorInfo = [$state, null, 'Database operation failed.'];
    }
}
