<?php

use App\Seller\Settings\Limits\DigestService;
use App\Seller\Settings\Limits\DigestTransport;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

require __DIR__.'/../../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestCase::assertIsolatedTestDatabase();
[$script, $id, $barrier, $worker] = $argv;
config(['features.E0-04.defaults.external_delivery' => true, 'mail.default' => 'smtp']);
if (getenv('DIGEST_TEST_NOW')) {
    Carbon::setTestNow(getenv('DIGEST_TEST_NOW'));
    CarbonImmutable::setTestNow(getenv('DIGEST_TEST_NOW'));
}
$app->instance(DigestTransport::class, new class($barrier) extends DigestTransport
{
    public function __construct(private string $barrier) {}

    public function send(string $channel, string $destination, array $titles): void
    {
        file_put_contents($this->barrier.'.calls', json_encode($titles, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        usleep(500000);
    }
});
file_put_contents($barrier.'.ready'.$worker, '1');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'.release') && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($barrier.'.release')) {
    throw new RuntimeException('Barrier timeout.');
}
$app->make(DigestService::class)->deliver($id);
