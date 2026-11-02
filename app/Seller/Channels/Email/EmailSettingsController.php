<?php

namespace App\Seller\Channels\Email;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

final class EmailSettingsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $settings = $this->access($request, fn (TenantContext $tenant) => DB::table('seller_email_price_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first(['sender_email', 'sender_name', 'enabled']));

        return Inertia::render('Seller/Channels/Email/Index', ['settings' => $settings, 'status' => session('status')]);
    }

    public function update(Request $request): mixed
    {
        $input = $request->validate(['sender_email' => ['required', 'email:rfc', 'max:254'], 'sender_name' => ['required', 'string', 'max:100'], 'enabled' => ['required', 'boolean']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $query = DB::table('seller_email_price_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
            $row = $query->first();
            $id = $row?->id ?? (string) Str::ulid();
            DB::table('seller_email_price_settings')->updateOrInsert(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId],
                ['id' => $id, 'sender_email' => $input['sender_email'], 'sender_name' => $input['sender_name'], 'enabled' => $input['enabled'], 'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.email_price.settings.updated', 'seller_email_price_settings', $id, after: ['enabled' => (bool) $input['enabled']]));
        });

        return back()->with('status', 'Настройки отправителя сохранены.');
    }
}
