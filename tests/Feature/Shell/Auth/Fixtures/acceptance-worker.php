<?php

use App\Models\User;
use App\Tenancy\Invitations\AcceptsInvitations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestCase::assertIsolatedTestDatabase();
[, $org, $userId, $token] = $argv;
config(['features.E0-02.defaults.organization_id' => $org]);
$user = User::findOrFail($userId);
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
fgets(STDIN);
$membership = app(AcceptsInvitations::class)->accept($user, $token);
echo json_encode(['role' => $membership->role], JSON_THROW_ON_ERROR);
