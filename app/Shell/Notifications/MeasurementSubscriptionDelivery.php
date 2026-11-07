<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class MeasurementSubscriptionDelivery
{
    public function deliver(DomainEvent $event): void
    {
        $e = $event->envelope;
        if ($e['type'] !== 'vision.measurement.completed' || config('runtime.notification_driver', config('runtime.delivery_driver')) !== 'fake') {
            return;
        }
        if (! DB::table('organizations')->where(['id' => $e['organization_id'], 'status' => 'active'])->exists()
            || ! DB::table('brands')->where(['id' => $e['brand_id'], 'organization_id' => $e['organization_id'], 'status' => 'active'])->exists()) {
            return;
        }
        $measurement = $e['data']['measurement_id'];
        if (! DB::table('measurements')->where(['id' => $measurement, 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'status' => 'completed'])->exists()) {
            return;
        }
        $rows = DB::table('vision_measurement_subscriptions')->where(['organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'measurement_id' => $measurement])->whereNull('sent_at')->where('expires_at', '>', now())->get();
        foreach ($rows as $row) {
            $member = Membership::withoutGlobalScopes()->where(['organization_id' => $e['organization_id'], 'user_id' => $row->user_id, 'status' => 'active'])->first();
            if (! $member || ! $member->permitsBrand($e['brand_id']) || ! $member->user) {
                continue;
            }
            $allowed = app(TenantContextRunner::class)->run(new TenantContext($e['organization_id'], $e['brand_id'], $member->user_id, $member->id, 'session'), fn (): bool => Gate::forUser($member->user)->allows('vision.measure.read'));
            if (! $allowed) {
                continue;
            }
            app(FakeTimingTransport::class)->send($e['organization_id'], $e['brand_id'], $row->user_id, 'email', ['Замер готов']);
            DB::table('vision_measurement_subscriptions')->where('id', $row->id)->update(['sent_at' => now(), 'updated_at' => now()]);
        }
    }
}
