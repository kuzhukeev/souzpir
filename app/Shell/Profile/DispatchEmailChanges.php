<?php

namespace App\Shell\Profile;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchEmailChanges extends Command
{
    protected $signature = 'profile:dispatch-email-changes {--limit=100}';

    protected $description = 'Schedule pending profile email-change intents; never retry uncertain sends.';

    public function handle(EmailChanges $changes): int
    {
        if (! $changes->available()) {
            $this->warn('Profile email delivery is disabled.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            return self::INVALID;
        }
        $queued = 0;
        foreach (DB::table('profile_email_changes')->where('status', 'pending')->orderBy('created_at')->limit($limit)->pluck('id') as $id) {
            try {
                Bus::dispatch((new DeliverEmailChange($id))->onQueue(config('runtime.queues.notifications'))->afterCommit());
                $queued++;
            } catch (Throwable) {
                // Retain a durable pending row. No address, token or transport exception is logged.
            }
        }
        $this->info('Queued pending intents: '.$queued);

        return self::SUCCESS;
    }
}
