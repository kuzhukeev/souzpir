<?php

use App\Models\Membership;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use App\Shell\Notifications\DomainEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $expected || DB::connection()->getDatabaseName() !== config('runtime.test_database') || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new RuntimeException('Unexpected database.');
}
[$script, $eventId, $userId, $barrier] = $argv;
$row = DB::table('outbox_events')->where('id', $eventId)->first();
$event = new DomainEvent(json_decode($row->envelope, true, 512, JSON_THROW_ON_ERROR));
$member = Membership::withoutGlobalScopes()->where('organization_id', $event->envelope['organization_id'])->where('user_id', $userId)->firstOrFail();
config(['features.E6-07.defaults.digest_enabled' => true]);
CarbonImmutable::setTestNow('2026-10-31T05:00:00Z');
file_put_contents($barrier.'.route-ready', '1');
DB::transaction(fn () => $app->make(SellerNotificationRouter::class)->route($event, $member, 'cabinet'));
