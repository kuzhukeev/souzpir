<?php

namespace App\Seller\Channels\Widget;

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

final class WidgetSettingsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function settings(TenantContext $tenant): object
    {
        $query = DB::table('widget_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
        $row = $query->first();
        if ($row) {
            return $row;
        }
        $id = (string) Str::ulid();
        DB::table('widget_settings')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'public_key' => Str::random(64), 'origins' => '[]', 'appearance' => json_encode(['title' => 'Напишите нам', 'color' => '#245c41', 'position' => 'right']),
            'behavior' => json_encode(['delay_seconds' => null, 'scroll_percent' => null, 'invitation' => 'Здравствуйте! Чем помочь?']), 'created_at' => now(), 'updated_at' => now()]);

        return $query->first();
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (User $user, TenantContext $tenant) use ($request): array {
            $row = $this->settings($tenant);

            return ['settings' => ['enabled' => $row->enabled, 'origins' => json_decode($row->origins, true),
                'appearance' => json_decode($row->appearance, true), 'behavior' => json_decode($row->behavior, true),
                'last_seen_at' => $row->last_seen_at, 'key' => $row->public_key],
                'scriptUrl' => $request->getSchemeAndHttpHost().'/widget.js',
                'contactRequests' => DB::table('widget_contact_requests')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->orderByDesc('created_at')->limit(100)->get(['id', 'name', 'email', 'phone', 'origin', 'created_at'])];
        });

        return Inertia::render('Seller/Channels/Widget/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function update(Request $request): mixed
    {
        $input = $request->validate(['enabled' => ['required', 'boolean'], 'origins' => ['present', 'array', 'max:30'], 'origins.*' => ['required', 'string', 'max:255'],
            'appearance.title' => ['required', 'string', 'max:80'], 'appearance.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.position' => ['required', 'in:left,right'], 'behavior.delay_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'behavior.scroll_percent' => ['nullable', 'integer', 'min:1', 'max:100'], 'behavior.invitation' => ['required', 'string', 'max:300'],
            'behavior.collect_contact' => ['sometimes', 'boolean'], 'behavior.consent_url' => ['nullable', 'url:https', 'max:2048', 'required_if:behavior.collect_contact,true'],
            'behavior.exit_intent' => ['sometimes', 'boolean'], 'behavior.page_mask' => ['nullable', 'string', 'max:255', 'starts_with:/'], 'behavior.repeat_hours' => ['nullable', 'integer', 'min:1', 'max:720']]);
        $origins = [];
        foreach ($input['origins'] as $origin) {
            $value = strtolower(rtrim(trim($origin), '/'));
            $parts = parse_url($value);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
                || ! in_array($parts['scheme'], ['https', 'http'], true) || ($parts['scheme'] === 'http' && ! in_array($parts['host'], ['localhost', '127.0.0.1'], true))) {
                throw ValidationException::withMessages(['origins' => 'Укажите адреса сайтов целиком, например https://example.ru. HTTP разрешён только для localhost.']);
            }
            $origins[] = $value;
        }
        if ($input['enabled'] && ! $origins) {
            throw ValidationException::withMessages(['origins' => 'Добавьте хотя бы один сайт.']);
        }
        $this->access($request, function (User $user, TenantContext $tenant) use ($input, $origins): void {
            $row = $this->settings($tenant);
            DB::table('widget_settings')->where('id', $row->id)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->update(['enabled' => $input['enabled'], 'origins' => json_encode(array_values(array_unique($origins))),
                    'appearance' => json_encode($input['appearance'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'behavior' => json_encode($input['behavior'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.widget.settings.updated', 'widget_settings', $row->id));
        });

        return back()->with('status', 'Настройки виджета сохранены.');
    }
}
