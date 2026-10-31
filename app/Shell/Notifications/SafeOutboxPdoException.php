<?php

namespace App\Shell\Notifications;

use PDOException;

/** Retains SQLSTATE and Laravel concurrency detection without query or bindings. */
final class SafeOutboxPdoException extends PDOException
{
    public function __construct(string $state)
    {
        parent::__construct($state === '40P01' ? 'deadlock detected' : 'Outbox database operation failed.');
        $this->code = $state;
        $this->errorInfo = [$state, null, 'Outbox database operation failed.'];
    }
}
