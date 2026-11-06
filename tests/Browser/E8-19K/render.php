<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? 'full';
$report = [
    'visibility' => 64, 'share_of_voice' => 28, 'position' => 2.4, 'questions' => 24,
    'verified_errors' => 3, 'confirmed_facts' => 21, 'reviewed_facts' => 24,
    'competitors' => [['name' => 'Компания A', 'answers' => 28], ['name' => 'Компания Б', 'answers' => 14]],
    'site_readiness' => 72, 'site_criteria' => [['title' => 'Доступность сайта', 'status' => 'pass']],
    'private_answer' => 'PRIVATE_ANSWER_DO_NOT_PUBLISH',
];
if ($mode === 'full') {
    $report['history'] = [
        ['date' => '2026-10-01', 'visibility' => 44, 'share_of_voice' => 20, 'position' => 3.4, 'verified_errors' => 1],
        ['date' => '2026-11-01', 'visibility' => 64, 'share_of_voice' => 28, 'position' => 2.4, 'verified_errors' => 3],
    ];
}
if ($mode === 'empty') {
    $report = ['visibility' => null, 'share_of_voice' => null, 'position' => null, 'questions' => 0];
}
echo view('public-report.index', ['expired' => $mode === 'expired', 'brandName' => 'Пример бренда', 'publishedAt' => '2026-11-01', 'report' => $report])->render();
