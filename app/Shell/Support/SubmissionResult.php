<?php

namespace App\Shell\Support;

use App\Shell\Support\Models\Ticket;

final readonly class SubmissionResult
{
    public function __construct(public Ticket $ticket, public bool $created) {}
}
