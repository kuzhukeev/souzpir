<?php

namespace App\Seller\Settings\Tools;

use App\Models\User;
use App\Seller\Engine\ToolCatalog;
use App\Seller\Engine\Tools\ToolCallJournal;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Shell\Support\FakeFieldRequestDelivery;
use App\Shell\Support\SupportActions;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
        [$settings, $callCounts, $toolsLoadError, $sources] = $this->access($request, function (User $user, TenantContext $tenant): array {
            $callCounts = [];
            $toolsLoadError = false;
            try {
                $since = now()->subDays(30);
                $scoped = fn (string $table) => DB::table($table)->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->where('created_at', '>=', $since)->where('created_at', '<=', now());
                $journal = app(ToolCallJournal::class);
                $legacyUntil = $journal->recordingSince($tenant) ?? now();
                $counts = [];
                foreach (array_keys(ToolCatalog::definitions()) as $tool) {
                    $count = $journal->countSince($tenant, $tool, $since);
                    if ($tool === 'create_promise') {
                        $count += $scoped('audit_logs')->where('action', 'seller.promise.created')->where('created_at', '<=', $legacyUntil)->count();
                    } elseif ($tool === 'escalate') {
                        $count += $scoped('seller_tasks')->where('type', 'handoff')->where('created_at', '<=', $legacyUntil)->count();
                    }
                    // Keep the legacy two keys always present (even null) for callers already
                    // reading them; the other seven only appear once the journal has a call.
                    if ($count > 0 || in_array($tool, ['create_promise', 'escalate'], true)) {
                        $counts[$tool] = $count > 0 ? $count : null;
                    }
                }
                $callCounts = $counts;
            } catch (QueryException) {
                $toolsLoadError = true;
            }

            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
            $pricePath = DB::table('catalog_settings')->where($scope)->value('price_file_path');
            $sources = ['price_file_available' => is_string($pricePath) && Storage::disk('local')->exists($pricePath),
                'catalog_available' => DB::table('catalog_imports')->where($scope)->where('status', 'completed')->exists()];

            return [app(ToolPreferences::class)->read($tenant), $callCounts, $toolsLoadError, $sources];
        });

        return Inertia::render('Seller/Settings/Tools/Tools', ['settings' => $settings, 'callCounts' => $callCounts, 'toolsLoadError' => $toolsLoadError, 'sources' => $sources, 'requestKey' => (string) Str::ulid(), 'status' => $request->session()->get('status')]);
    }

    public function handoff(Request $request): mixed
    {
        $settings = $this->access($request, fn (User $user, TenantContext $tenant) => app(ToolPreferences::class)->read($tenant));

        return Inertia::render('Seller/Settings/Tools/Handoff', ['settings' => $settings, 'status' => $request->session()->get('status')]);
    }

    public function updateTools(Request $request): mixed
    {
        $input = $request->validate(['tools' => ['required', 'array'], 'tools.*' => ['required', 'boolean'], 'requisites' => ['nullable', 'string', 'max:5000'],
            'price_source' => ['sometimes', 'in:file,catalog,link'], 'price_url' => ['nullable', 'url:https', 'max:2048', 'required_if:price_source,link'],
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
            if (array_key_exists('price_source', $input)) {
                $preferences['price_source'] = $input['price_source'];
                $preferences['price_url'] = $input['price_source'] === 'link' ? $input['price_url'] : '';
            }
            $preferences['delivery_rules'] = $input['delivery_rules'];
            $this->save($tenant, $preferences);
        });

        return back()->with('status', 'Настройки действий сохранены.');
    }

    public function requestAction(Request $request): mixed
    {
        $input = $request->validate(['description' => ['required', 'string', 'min:10', 'max:9500'],
            'submission_key' => ['required', 'ulid'], 'brand_id' => ['prohibited'], 'organization_id' => ['prohibited']]);
        $brandName = $this->access($request, fn (User $user, TenantContext $tenant) => DB::table('brands')
            ->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('name'));
        $submission = app(SupportActions::class)->create($request, ['subject' => 'other', 'module' => 'seller',
            'body' => 'Запрос действия v2 бренда «'.$brandName.'»: '.trim($input['description']), 'support_access' => false,
            'submission_key' => strtoupper($input['submission_key'])]);
        if ($submission->created) {
            app(FakeFieldRequestDelivery::class)->send($submission->ticket);
        }

        return back()->with('status', 'Заявка сохранена в поддержке. Письмо не отправлено.');
    }

    public function updateHandoff(Request $request): mixed
    {
        $input = $request->validate(['reasons' => ['present', 'array'], 'reasons.*.enabled' => ['required', 'boolean'],
            'reasons.*.priority' => ['required', 'in:normal,urgent'], 'reasons.*.message' => ['nullable', 'string', 'max:1000'],
            'reasons.*.after' => ['required', 'in:silent,simple,continue'], 'timeout_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'timeout_notification' => ['sometimes', 'boolean'], 'timeout_reminder' => ['sometimes', 'boolean'],
            'timeout_fallback' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $preferences = app(ToolPreferences::class)->read($tenant);
            if (array_diff(array_keys($input['reasons']), array_keys($preferences['reasons']))) {
                throw ValidationException::withMessages(['reasons' => 'Неизвестная причина передачи.']);
            }
            foreach ($input['reasons'] as $id => $reason) {
                $preferences['reasons'][$id] = array_replace($preferences['reasons'][$id], $reason);
            }
            $preferences['timeout_minutes'] = isset($input['timeout_minutes']) ? (int) $input['timeout_minutes'] : null;
            foreach (['timeout_notification', 'timeout_reminder'] as $key) {
                if (array_key_exists($key, $input)) {
                    $preferences[$key] = (bool) $input[$key];
                }
            }
            if (array_key_exists('timeout_fallback', $input)) {
                $preferences['timeout_fallback'] = $input['timeout_fallback'] ?? '';
            }
            $this->save($tenant, $preferences);
        });

        return back()->with('status', 'Настройки передачи сохранены.');
    }
}
