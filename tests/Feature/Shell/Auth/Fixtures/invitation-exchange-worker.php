<?php

use App\Shell\Auth\InvitationExchangeGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['features.E0-02.defaults.organization_id' => $data['org']]);
$request = Request::create('/invitations/exchange', 'POST');
$request->setLaravelSession(new Store('test', new ArraySessionHandler(120), $data['session']));
fwrite(STDOUT, 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n");
try {
    $ok = app(InvitationExchangeGrants::class)->exchange($request, $data['hash'], $data['epoch'], 1);
    fwrite(STDOUT, $ok ? "ok\n" : "denied\n");
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error)."\n");
    exit(1);
}
