<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo view('public-report.index', ['expired' => false, 'brandName' => 'Пример бренда', 'publishedAt' => '2026-11-04', 'report' => [
    'allow_pdf' => false, 'visibility' => 42, 'coverage' => 80, 'share_of_voice' => 15, 'position' => 2, 'questions' => 10,
    'site_readiness' => null, 'competitors' => [], 'actions' => [],
    'history' => [['date' => '2026-10-04', 'visibility' => 20, 'coverage' => 70, 'share_of_voice' => 10, 'position' => 3],
        ['date' => '2026-11-04', 'visibility' => 42, 'coverage' => 80, 'share_of_voice' => 15, 'position' => 2]],
]])->render();
