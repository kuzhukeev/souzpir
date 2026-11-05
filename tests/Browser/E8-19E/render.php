<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo view('public-report.index', ['expired' => false, 'brandName' => 'Пример бренда', 'publishedAt' => '2026-11-04', 'report' => [
    'allow_pdf' => true, 'visibility' => 42, 'coverage' => 80, 'share_of_voice' => 15, 'position' => 2, 'questions' => 10,
    'site_readiness' => ['lower' => 35, 'upper' => 60], 'site_criteria' => [['title' => 'Правила доступа роботов', 'status' => 'pass']],
    'competitors' => [['name' => 'Компания А', 'answers' => 8]], 'actions' => ['Уточнить описание товаров'],
]])->render();
