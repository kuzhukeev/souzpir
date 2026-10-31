<?php

namespace App\Shell\Auth\PasswordDelivery;

use Illuminate\Console\Command;

final class DispatchPending extends Command
{
    protected $signature = 'auth:password-dispatch-pending {--limit=100}';

    protected $description = 'Dispatch pending password recovery intents; never resend uncertain deliveries.';

    public function handle(PasswordRequests $requests): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 500.');

            return self::FAILURE;
        }
        $result = $requests->dispatchPending($limit);
        $this->info('Queued: '.$result['queued'].'; dispatch failures: '.$result['failed']);

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
