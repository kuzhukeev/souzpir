<?php

namespace Tests\Feature\Runtime\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

final class PersistentProbeJob implements ShouldQueue
{
    public function __construct(public string $marker) {}

    public function handle(): void
    {
        file_put_contents($this->marker, 'completed');
    }
}
