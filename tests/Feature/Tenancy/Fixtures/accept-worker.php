<?php

use App\Models\User;
use App\Tenancy\Invitations\AcceptsInvitations;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Separate PostgreSQL connection for the real concurrency integration test.
require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
fwrite(STDOUT, "ready\n");
try {
    $user = User::query()->findOrFail(getenv('TENANCY_TEST_ACTOR'));
    $membership = app(AcceptsInvitations::class)->accept($user, getenv('TENANCY_TEST_TOKEN'));
    fwrite(STDOUT, "accepted\n");
} catch (HttpException $e) {
    fwrite(STDOUT, 'denied:'.$e->getStatusCode()."\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'error:'.$e::class."\n");
    exit(1);
}
