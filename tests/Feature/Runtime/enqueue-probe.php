<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Queue::connection('database')->pushOn('souzpir-test-e007:default', new Tests\Feature\Runtime\Fixtures\RuntimeProbeJob);
