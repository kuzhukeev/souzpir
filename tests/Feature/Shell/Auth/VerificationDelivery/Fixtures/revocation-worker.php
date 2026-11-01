<?php

use App\Models\User;
use App\Shell\Auth\VerificationDelivery\VerificationDelivery;
use App\Shell\Auth\VerificationDelivery\VerificationTransport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require __DIR__.'/../../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestCase::assertIsolatedTestDatabase();
[, $org, $intentId, $phase] = $argv;
config(['features.E0-02.defaults.organization_id' => $org, 'features.E1-01D.defaults.enabled' => true]);
$delivery = app(VerificationDelivery::class);
$claim = $phase === 'send' ? $delivery->claim($intentId) : null;
if ($phase === 'send' && ! $claim) {
    throw new LogicException('Unable to acquire fixture claim.');
}
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
fgets(STDIN);
if ($phase === 'send') {
    $transport = new class implements VerificationTransport
    {
        public int $calls = 0;

        public function send(User $user): bool
        {
            $this->calls++;

            return true;
        }
    };
    $delivery->send($claim, $transport);
    $calls = $transport->calls;
} else {
    $claim = $delivery->claim($intentId);
    $calls = $claim ? 1 : 0;
}
echo json_encode(['calls' => $calls, 'status' => DB::table('verification_delivery_intents')->where('id', $intentId)->value('status')], JSON_THROW_ON_ERROR);
