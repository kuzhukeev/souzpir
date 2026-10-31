<?php

namespace App\Ai\Drivers;

use RuntimeException;

/** Known unbilled rejection: releasing a reservation and an explicit retry are safe. */
final class Rejected extends RuntimeException {}
