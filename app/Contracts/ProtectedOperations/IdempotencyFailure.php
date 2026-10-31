<?php

namespace App\Contracts\ProtectedOperations;

use RuntimeException;

final class IdempotencyFailure extends RuntimeException {}
