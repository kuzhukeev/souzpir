<?php

use App\Shell\Auth\InvitationExchangeGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== getenv('E106_TEST_DB')
    || ! str_starts_with((string) getenv('E106_TEST_DB'), 'souzpir_test_')
    || config('runtime.preview_database') === getenv('E106_TEST_DB')) {
    throw new LogicException('Non-isolated test database.');
}
$session = $app['session']->driver();
$session->setId(Str::random(40));
$session->start();
$request = Request::create('/invitations/exchange', 'POST');
$request->setLaravelSession($session);
$service = app(InvitationExchangeGrants::class);
$epoch = $service->beginDocument($request);
fwrite(STDOUT, "ready\n");
try {
    $ok = $service->exchange($request, getenv('E106_TOKEN_HASH'), $epoch, 1);
    fwrite(STDOUT, $ok ? "exchanged\n" : "denied\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'error:'.$exception::class."\n");
    exit(1);
}
