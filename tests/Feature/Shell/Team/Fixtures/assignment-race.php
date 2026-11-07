<?php

use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\SellerTask;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$configured = $db->getDatabaseName();
if (! $app->environment('testing') || $db->getDriverName() !== 'pgsql'
    || ! preg_match('/(?:^|_)test(?:_|$)/', $configured)
    || $configured !== config('runtime.test_database') || $configured === config('runtime.preview_database')
    || $db->selectOne('select current_database() as name')->name !== $configured
    || realpath(base_path()) !== realpath(getcwd())) {
    fwrite(STDERR, 'Unsafe test environment.');
    exit(2);
}
Http::preventStrayRequests();
Http::fake();
Mail::fake();
Queue::fake();
[$script, $organization, $brand, $user, $record, $kind, $order] = $argv;
$context = new TenantContext($organization, $brand, source: 'job');
echo 'ready:'.$db->selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
fgets(STDIN);
try {
    app(TenantContextRunner::class)->run($context, function () use ($kind, $record, $user, $order): void {
        DB::transaction(function () use ($kind, $record, $user, $order): void {
            $model = $kind === 'conversation' ? Conversation::findOrFail($record) : SellerTask::findOrFail($record);
            $model->assigned_user_id = $user;
            $model->save();
            if ($order === 'assignment-first') {
                echo "assigned\n";
                flush();
                // Keep the actual Seller transaction open while Team attempts its change.
                usleep(2000000);
            }
        });
    });
    echo "committed\n";
} catch (LogicException|ValidationException $error) {
    echo "rejected\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Unexpected assignment error: '.get_class($error));
    exit(3);
}
