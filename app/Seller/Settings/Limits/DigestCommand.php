<?php

namespace App\Seller\Settings\Limits;

use Illuminate\Console\Command;

final class DigestCommand extends Command
{
    protected $signature = 'seller:notification-digests';

    protected $description = 'Process due seller notification digests';

    public function handle(DigestService $service): int
    {
        $service->run();

        return self::SUCCESS;
    }
}
