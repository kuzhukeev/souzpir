<?php

use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\SellerTask;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)test(?:_|$)/', $db->getDatabaseName()) || $db->getDatabaseName() !== config('runtime.test_database') || $db->getDatabaseName() === config('runtime.preview_database') || $db->selectOne('select current_database() as name')->name !== $db->getDatabaseName()) {
    fwrite(STDERR, 'Unsafe test database.');
    exit(2);
}
[$script, $org, $brand, $user, $id, $kind, $quiet] = $argv;
$context = new TenantContext($org, $brand, source: 'job');
try {
    app(TenantContextRunner::class)->run($context, function () use ($user, $id, $kind, $quiet): void {
        $record = $kind === 'task' ? SellerTask::findOrFail($id) : Conversation::findOrFail($id);
        $record->assigned_user_id = $user;
        $quiet === 'quiet' ? $record->saveQuietly() : $record->save();
    });
    fwrite(STDOUT, "COMMITTED\n");
} catch (LogicException $error) {
    if ($error->getMessage() !== 'Assignee cannot operate in this brand.') {
        fwrite(STDERR, 'Unexpected fixture rejection.');
        exit(3);
    }
    fwrite(STDOUT, "REJECTED\n");
} catch (Throwable) {
    fwrite(STDERR, 'Assignment fixture failed.');
    exit(4);
}
