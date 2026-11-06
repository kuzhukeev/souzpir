<?php

namespace App\Vision\Measurements;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MeasurementSubscriptionController
{
    public function show(Request $request, string $measurement): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($measurement): mixed {
            $this->measurement($tenant, $measurement);
            $row = DB::table('vision_measurement_subscriptions')->where(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'user_id' => $tenant->actorId, 'measurement_id' => $measurement])->whereNull('sent_at')->where('expires_at', '>', now())->first(['expires_at']);

            return response()->json(['subscribed' => $row !== null, 'expires_at' => $row?->expires_at]);
        });
    }

    public function update(Request $request, string $measurement): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($request, $measurement): mixed {
            $data = $request->validate(['enabled' => ['required', 'boolean']]);
            $run = $this->measurement($tenant, $measurement);
            $key = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'user_id' => $tenant->actorId, 'measurement_id' => $measurement];
            if (! $data['enabled']) {
                DB::table('vision_measurement_subscriptions')->where($key)->delete();

                return response()->json(['subscribed' => false, 'expires_at' => null]);
            }
            if (! in_array($run->status, ['pending', 'running', 'in_progress'], true)) {
                throw ValidationException::withMessages(['enabled' => 'Замер уже завершён.']);
            }
            $expires = now()->addDays(7);
            DB::table('vision_measurement_subscriptions')->updateOrInsert($key, ['id' => (string) Str::ulid(), 'expires_at' => $expires, 'sent_at' => null, 'created_at' => now(), 'updated_at' => now()]);

            return response()->json(['subscribed' => true, 'expires_at' => $expires->toIso8601String()]);
        });
    }

    private function measurement(TenantContext $tenant, string $id): object
    {
        return DB::table('measurements')->where(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId])->first(['id', 'status']) ?? abort(404);
    }
}
