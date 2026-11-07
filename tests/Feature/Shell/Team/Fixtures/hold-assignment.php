<?php

use App\Seller\Conversations\ActiveTenantLock;
use App\Seller\Conversations\Models\SellerTask;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Offline child process: reserve the same organization lock as the Seller repository.
require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)test(?:_|$)/', $db->getDatabaseName()) || $db->getDatabaseName() !== config('runtime.test_database') || $db->getDatabaseName() === config('runtime.preview_database') || $db->selectOne('select current_database() as name')->name !== $db->getDatabaseName()) {
    fwrite(STDERR, 'Unsafe test database.');
    exit(2);
}
[$script, $org, $brand, $user, $conversation] = $argv;
$context = new TenantContext($org, $brand, source: 'job');
try {
    app(TenantContextRunner::class)->run($context, function () use ($context, $user, $conversation): void {
        DB::transaction(function () use ($context, $user, $conversation): void {
            ActiveTenantLock::acquire($context);
            SellerTask::create(['conversation_id' => $conversation, 'type' => 'followup', 'assigned_user_id' => $user, 'status' => 'new']);
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT);
            usleep(2000000);
        });
    });
    fwrite(STDOUT, "committed\n");
} catch (Throwable) {
    fwrite(STDERR, 'Assignment fixture failed.');
    exit(3);
}
