<?php

namespace App\Ai\Drivers;

use RuntimeException;

/** Outcome may have been billed; retain reservation and never retry automatically. */
final class Uncertain extends RuntimeException {}
