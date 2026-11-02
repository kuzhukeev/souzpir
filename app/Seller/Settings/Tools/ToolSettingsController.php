<?php

namespace App\Seller\Settings\Tools;

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

final class ToolSettingsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function save(TenantContext $tenant, array $data): void
    {
        $row = DB::table('seller_tool_preferences')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        $id = $row?->id ?? (string) Str::ulid();
        DB::table('seller_tool_preferences')->updateOrInsert(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId],
            ['id' => $id, 'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => $row?->created_at ?? now()]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.tools.settings.updated', 'seller_tool_preferences', $id));
    }

    public function tools(Request $request): mixed
    {
        $settings = $this->access($request, fn (User $user, TenantContext $tenant) => app(ToolPreferences::class)->read($tenant));

        return Inertia::render('Seller/Settings/Tools/Tools', ['settings' => $settings, 'status' => $request->session()->get('status')]);
    }

    public function handoff(Request $request): mixed
    {
        $settings = $this->access($request, fn (User $user, TenantContext $tenant) => app(ToolPreferences::class)->read($tenant));

        return Inertia::render('Seller/Settings/Tools/Handoff', ['settings' => $settings, 'status' => $request->session()->get('status')]);
    }

    public function updateTools(Request $request): mixed
    {
        $input = $request->validate(['tools' => ['required', 'array'], 'tools.*' => ['required', 'boolean'], 'requisites' => ['nullable', 'string', 'max:5000'],
            'delivery_rules' => ['present', 'array', 'max:100'], 'delivery_rules.*.city' => ['required', 'string', 'max:255'],
            'delivery_rules.*.price_min' => ['required', 'integer', 'min:0'], 'delivery_rules.*.price_max' => ['required', 'integer', 'gte:delivery_rules.*.price_min'],
            'delivery_rules.*.days_min' => ['required', 'integer', 'min:0'], 'delivery_rules.*.days_max' => ['required', 'integer', 'gte:delivery_rules.*.days_min']]);
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $preferences = app(ToolPreferences::class)->read($tenant);
            if (array_diff(array_keys($input['tools']), array_keys($preferences['tools']))) {
                throw ValidationException::withMessages(['tools' => 'Неизвестное действие.']);
            }
            foreach (ToolPreferences::REQUIRED as $required) {
                if (($input['tools'][$required] ?? true) !== true) {
                    throw ValidationException::withMessages(['tools' => 'Обязательное действие нельзя выключить.']);
                }
            }
            $preferences['tools'] = array_replace($preferences['tools'], $input['tools']);
            $preferences['requisites'] = $input['requisites'] ?? '';
            $preferences['delivery_rules'] = $input['delivery_rules'];
            $this->save($tenant, $preferences);
        });

        return back()->with('status', 'Настройки действий сохранены.');
    }

    public function updateHandoff(Request $request): mixed
    {
        $input = $request->validate(['reasons' => ['present', 'array'], 'reasons.*.enabled' => ['required', 'boolean'],
            'reasons.*.priority' => ['required', 'in:normal,urgent'], 'reasons.*.message' => ['nullable', 'string', 'max:1000'],
            'reasons.*.after' => ['required', 'in:silent,simple,continue'], 'timeout_minutes' => ['nullable', 'integer', 'min:1', 'max:10080']]);
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $preferences = app(ToolPreferences::class)->read($tenant);
            if (array_diff(array_keys($input['reasons']), array_keys($preferences['reasons']))) {
                throw ValidationException::withMessages(['reasons' => 'Неизвестная причина передачи.']);
            }
            foreach ($input['reasons'] as $id => $reason) {
                $preferences['reasons'][$id] = array_replace($preferences['reasons'][$id], $reason);
            }
            $preferences['timeout_minutes'] = isset($input['timeout_minutes']) ? (int) $input['timeout_minutes'] : null;
            $this->save($tenant, $preferences);
        });

        return back()->with('status', 'Настройки передачи сохранены.');
    }
}
