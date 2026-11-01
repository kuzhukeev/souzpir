<?php

namespace Tests\Feature\Runtime\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

final class RuntimeProbeJob implements ShouldQueue
{
    public function handle(): void
    {
        file_put_contents(base_path('.local/runtime/e007-probe.txt'), 'fake job complete');
    }
}
