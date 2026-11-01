<?php

use App\Models\User;
use App\Shell\Auth\PasswordDelivery\PasswordDelivery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require __DIR__.'/../../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestCase::assertIsolatedTestDatabase();
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['features.E0-02.defaults.organization_id' => $input['org'], 'features.E1-01F.defaults.enabled' => true]);
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
fgets(STDIN);
$user = User::findOrFail($input['user']);
echo app(PasswordDelivery::class)->enqueue($user->email) === null ? 'skipped' : 'enqueued';
