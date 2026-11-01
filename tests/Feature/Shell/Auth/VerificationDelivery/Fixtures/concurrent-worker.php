<?php

use App\Models\User;
use App\Shell\Auth\VerificationDelivery\VerificationDelivery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require __DIR__.'/../../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestCase::assertIsolatedTestDatabase();
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['features.E0-02.defaults.organization_id' => $data['org'], 'features.E1-01D.defaults.enabled' => true]);
$user = User::findOrFail($data['user']);
$session = app('session')->driver('array');
$session->setId($data['session']);
$session->start();
$session->put('auth.version', $user->auth_version);
$request = Request::create('/verification', 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
$delivery = app(VerificationDelivery::class);
try {
    $result = $delivery->enqueue($request, $data['key']);
} catch (AuthorizationException) {
    echo 'denied';
    exit(0);
}
$claim = $delivery->claim($result['id']);
echo json_encode(['id' => $result['id'], 'claimed' => $claim !== null], JSON_THROW_ON_ERROR);
