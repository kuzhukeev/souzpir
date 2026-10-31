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
if (DB::connection()->getDatabaseName() !== 'souzpir_test_sp_e6_07') {
    throw new RuntimeException('Unexpected database.');
}
[$script, $eventId, $userId, $barrier] = $argv;
$row = DB::table('outbox_events')->where('id', $eventId)->first();
$event = new DomainEvent(json_decode($row->envelope, true, 512, JSON_THROW_ON_ERROR));
$member = Membership::withoutGlobalScopes()->where('organization_id', $event->envelope['organization_id'])->where('user_id', $userId)->firstOrFail();
CarbonImmutable::setTestNow('2026-10-31T05:00:00Z');
file_put_contents($barrier.'.route-ready', '1');
DB::transaction(fn () => $app->make(SellerNotificationRouter::class)->route($event, $member, 'cabinet'));
