<?php

namespace App\Seller\Channels\Api;

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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ApiSettingsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (User $user, TenantContext $tenant): array {
            $mapping = DB::table('seller_api_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('mapping');
            $logs = DB::table('seller_api_lead_requests')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->orderByDesc('created_at')->limit(20)->get(['id', 'endpoint', 'status', 'created_at']);

            return ['mapping' => $mapping ? json_decode($mapping, true) : LeadIngressController::DEFAULT_MAPPING, 'logs' => $logs];
        });

        return Inertia::render('Seller/Channels/Api/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function update(Request $request): mixed
    {
        $input = $request->validate(['mapping' => ['required', 'array', 'min:1', 'max:30'], 'mapping.*' => ['required', 'string', 'in:'.implode(',', LeadIngressController::TARGETS)]]);
        foreach (array_keys($input['mapping']) as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name)) {
                throw ValidationException::withMessages(['mapping' => 'Имена полей: латиница, цифры, дефис и подчёркивание.']);
            }
        }
        if (! array_intersect(['name', 'phone', 'email'], $input['mapping']) || count(array_unique($input['mapping'])) !== count($input['mapping'])) {
            throw ValidationException::withMessages(['mapping' => 'Выберите имя, телефон или почту; поля назначения не должны повторяться.']);
        }
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $row = DB::table('seller_api_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
            $id = $row?->id ?? (string) Str::ulid();
            DB::table('seller_api_settings')->updateOrInsert(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId],
                ['id' => $id, 'mapping' => json_encode($input['mapping'], JSON_THROW_ON_ERROR), 'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.api.mapping.updated', 'seller_api_settings', $id));
        });

        return back()->with('status', 'Соответствие полей сохранено.');
    }
}
