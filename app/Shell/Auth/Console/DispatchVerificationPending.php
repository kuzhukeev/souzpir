<?php

namespace App\Shell\Auth\Console;

use App\Shell\Auth\VerificationRequests;
use Illuminate\Console\Command;

final class DispatchVerificationPending extends Command
{
    protected $signature = 'auth:verification-dispatch-pending {--limit=100}';

    protected $description = 'Schedule bounded pending verification intents; never resend uncertain deliveries.';

    public function handle(VerificationRequests $requests): int
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
