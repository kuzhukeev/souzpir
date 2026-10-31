<?php

use App\Agent\Knowledge;
use App\Agent\Tools\ToolRunner;

// Optional, read-only provenance check. This script never writes the fixture.
require __DIR__.'/../../../../../vendor/autoload.php';
$reference = getenv('SOUZPIR_PROTOTYPE_ROOT') ?: 'C:/OSPanel/home/souzpir.local/.local/references/laravel-prototype';
$fixture = json_decode(file_get_contents(__DIR__.'/followup-prototype-synthetic.json'), true, 512, JSON_THROW_ON_ERROR);
$toolFile = $reference.'/app/Agent/Tools/ToolRunner.php';
if (hash_file('sha256', $toolFile) !== $fixture['source_sha256']) {
    throw new RuntimeException('Prototype source does not match the captured fixture.');
}
require $reference.'/app/Agent/Knowledge.php';
require $toolFile;

$knowledge = new class($fixture['default_days']) extends Knowledge
{
    public function __construct(private readonly int $days) {}

    public function conditions(): array
    {
        return ['followup_default_days' => $this->days];
    }
};
$runner = new ToolRunner($knowledge);
$oldTimezone = date_default_timezone_get();
try {
    foreach ($fixture['cases'] as $case) {
        Carbon\Carbon::setTestNowAndTimezone(Carbon\Carbon::parse($case['now'], $case['timezone']));
        if ($runner->parseWhen($case['input'])->format('Y-m-d H:i:s P') !== $case['prototype']) {
            throw new RuntimeException('Prototype fixture mismatch.');
        }
    }
} finally {
    Carbon\Carbon::setTestNow();
    date_default_timezone_set($oldTimezone);
}
echo count($fixture['cases']), " synthetic prototype cases matched.\n";
