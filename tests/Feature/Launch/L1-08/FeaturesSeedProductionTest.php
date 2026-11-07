<?php

namespace Tests\Feature\Launch\L1_08;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class FeaturesSeedProductionTest extends TestCase
{
    public function test_features_seed_refuses_outside_local_and_testing(): void
    {
        $db = config('database.connections.pgsql');
        $process = new Process([PHP_BINARY, 'artisan', 'features:seed', '--all', '--no-interaction'], base_path(), [
            'APP_ENV' => 'production', 'DB_URL' => '', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
            'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'log', 'AI_DRIVER' => 'fake',
            'DELIVERY_DRIVER' => 'fake', 'FEATURES_SCHEDULING_ENABLED' => 'false',
        ], null, 60);

        $process->run();

        self::assertFalse($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('available only in local/testing', $process->getOutput());
    }
}
