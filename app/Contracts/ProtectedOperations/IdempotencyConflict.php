<?php

namespace App\Contracts\ProtectedOperations;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException {}
