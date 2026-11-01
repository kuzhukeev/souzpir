<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Runtime\Fixtures\RuntimeProbeJob;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Queue::connection('database')->pushOn('souzpir-test-e007:default', new RuntimeProbeJob);
