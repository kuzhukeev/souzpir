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
            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
            $successes = DB::table('seller_api_lead_requests')->where($scope)->whereIn('status', ['created', 'opened'])->orderByDesc('created_at')->limit(20)
                ->get(['id', 'endpoint', 'created_at'])->map(fn ($row) => ['id' => $row->id, 'endpoint' => $row->endpoint,
                    'status' => 'success', 'error' => null, 'created_at' => $row->created_at]);
            $turns = DB::table('protected_operation_receipts')->where($scope)->where('operation', 'seller.api.turn')->whereNotNull('result_ciphertext')
                ->orderByDesc('created_at')->limit(20)->get(['id', 'created_at'])->map(fn ($row) => ['id' => $row->id, 'endpoint' => 'turn',
                    'status' => 'success', 'error' => null, 'created_at' => $row->created_at]);
            $failures = DB::table('seller_api_request_failures')->where($scope)->orderByDesc('created_at')->limit(20)
                ->get(['id', 'endpoint', 'http_status', 'created_at'])->map(fn ($row) => ['id' => $row->id, 'endpoint' => $row->endpoint,
                    'status' => 'error', 'error' => 'HTTP '.(int) $row->http_status, 'created_at' => $row->created_at]);
            $logs = $successes->concat($turns)->concat($failures)->sortByDesc('created_at')->take(20)->values();
            $sources = app(ApiSourceReceipt::class)->forEvents($tenant->organizationId, $tenant->brandId, $logs->pluck('id')->all());
            $logs = $logs->map(fn ($row) => [...$row, 'source' => $sources[$row['id']] ?? null])->all();

            $settings = DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)->value('settings');
            $brandSettings = is_string($settings) ? json_decode($settings, true) : [];
            $action = ($brandSettings['seller_api_action'] ?? null) === 'open' ? 'open' : 'card_only';

            return ['mapping' => $mapping ? json_decode($mapping, true) : LeadIngressController::DEFAULT_MAPPING, 'logs' => $logs, 'action' => $action];
        });

        return Inertia::render('Seller/Channels/Api/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function updateAction(Request $request): mixed
    {
        $input = $request->validate(['action' => ['required', 'in:card_only,open']]);
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $brand = DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)->lockForUpdate()->first(['settings']);
            $settings = json_decode($brand->settings, true, 512, JSON_THROW_ON_ERROR);
            $settings['seller_api_action'] = $input['action'];
            DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)
                ->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.api.action.updated', 'brand', $tenant->brandId));
        });

        return back()->with('status', 'Действие для нового лида сохранено.');
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
