<?php

namespace App\Shell\Organizations\Http;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Plans\LimitEnforcer;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class OrganizationController
{
    public function company(): Response
    {
        Gate::authorize('org.settings.write');
        $ctx = app(TenantContext::class);
        $org = Organization::whereKey($ctx->organizationId)->firstOrFail();
        $owner = User::whereKey($org->owner_user_id)->first();
        $profile = $org->settings['profile'] ?? [];
        $candidates = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'active')->where('user_id', '!=', $org->owner_user_id)->with('user:id,name,email')->get()->filter(fn ($item) => $item->user !== null)->map(fn ($item) => ['id' => $item->user_id, 'name' => $item->user->name, 'email' => $item->user->email])->values();

        return Inertia::render('Shell/Organizations/Company', [
            'company' => ['id' => $org->id, 'name' => $org->name, 'legal_name' => $profile['legal_name'] ?? '', 'inn' => $profile['inn'] ?? '', 'industry' => $profile['industry'] ?? '', 'country' => $profile['country'] ?? '', 'region' => $profile['region'] ?? '', 'timezone' => $profile['timezone'] ?? 'Europe/Moscow'],
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
            'candidates' => Gate::allows('org.owner.transfer') ? $candidates : [],
            'permissions' => ['manage' => true, 'transfer' => Gate::allows('org.owner.transfer')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('org.settings.write');
        $data = $this->validateAt($request, route('organization.show'), ['name' => ['required', 'string', 'max:160'], 'legal_name' => ['nullable', 'string', 'max:255'], 'inn' => ['nullable', 'regex:/^(?:[0-9]{10}|[0-9]{12})$/'], 'industry' => ['nullable', 'string', 'max:120'], 'country' => ['nullable', 'string', 'max:120'], 'region' => ['nullable', 'string', 'max:120'], 'timezone' => ['required', 'timezone']]);
        $ctx = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($ctx, $data): void {
            [$org] = $this->lockAdmin($ctx);
            $name = trim($data['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Укажите название компании.'])->redirectTo(route('organization.show'));
            }
            $settings = $org->settings ?? [];
            $settings['profile'] = array_merge($settings['profile'] ?? [], array_intersect_key($data, array_flip(['legal_name', 'inn', 'industry', 'country', 'region', 'timezone'])));
            $org->forceFill(['name' => $name, 'settings' => $settings])->save();
            $this->audit($ctx, 'organization.updated', 'organization', $org->id, ['fields' => array_keys($data)]);
        });

        return redirect()->route('organization.show');
    }

    public function transfer(Request $request): RedirectResponse
    {
        Gate::authorize('org.owner.transfer');
        $data = $this->validateAt($request, route('organization.show'), ['user_id' => ['required', 'ulid'], 'confirm_name' => ['required', 'string', 'max:160']]);
        $ctx = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($ctx, $data): void {
            $org = Organization::whereKey($ctx->organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $members = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->whereIn('user_id', [$ctx->actorId, $data['user_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('user_id');
            $actor = $members->get($ctx->actorId);
            $target = $members->get($data['user_id']);
            abort_unless($actor && $actor->id === $ctx->membershipId && $actor->role === 'owner' && $actor->status === 'active' && $org->owner_user_id === $ctx->actorId, 403);
            $this->assertActiveBrandAccess($ctx, $actor, $org->id);
            if (! hash_equals($org->name, $data['confirm_name'])) {
                throw ValidationException::withMessages(['confirm_name' => 'Название компании не совпадает.'])->redirectTo(route('organization.show'));
            }
            if (! $target || $target->status !== 'active' || $target->user_id === $actor->user_id) {
                throw ValidationException::withMessages(['user_id' => 'Выберите другого активного участника.'])->redirectTo(route('organization.show'));
            }
            $targetSettings = $target->settings ?? [];
            $targetSettings['sales_manager'] = false;
            $target->forceFill(['role' => 'owner', 'brand_ids' => [], 'settings' => $targetSettings])->save();
            $org->forceFill(['owner_user_id' => $target->user_id])->save();
            $actor->forceFill(['role' => 'admin'])->save();
            $this->audit($ctx, 'organization.owner.transferred', 'organization', $org->id, ['from_user_id' => $actor->user_id, 'to_user_id' => $target->user_id]);
        });

        return redirect()->route('organization.show');
    }

    public function brands(Request $request, ModuleEntitlements $modules, PlanLimits $limits): Response
    {
        Gate::authorize('brand.write');
        $ctx = app(TenantContext::class);
        $membership = $request->attributes->get('tenant.membership');
        $rows = Brand::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)->orderBy('name')->orderBy('id')->get()->filter(fn ($brand) => $membership->permitsBrand($brand->id));
        $members = Membership::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)->where('status', 'active')->get();
        $items = $rows->map(function ($brand) use ($ctx, $modules, $members) {
            $target = $this->forBrand($ctx, $brand->id);

            return ['id' => $brand->id, 'name' => $brand->name, 'website' => $brand->website, 'theme' => $brand->theme, 'status' => $brand->status, 'logo_url' => ($brand->settings['logo_path'] ?? null) ? route('brands.logo', $brand->id) : null,
                'member_count' => $members->filter(fn ($member) => $member->permitsBrand($brand->id))->count(), 'modules' => ['seller' => $modules->enabled($target, 'seller'), 'vision' => $modules->enabled($target, 'vision')]];
        })->values();
        $limit = $limits->get($ctx, 'brands.max');

        return Inertia::render('Shell/Organizations/Brands', ['items' => $items, 'activeCount' => Brand::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)->where('status', 'active')->count(), 'brandLimit' => ['value' => $limit->value, 'configured' => $limit->configured, 'unit' => $limit->unit], 'permissions' => ['manage' => true]]);
    }

    public function createBrand(Request $request, LimitEnforcer $limits): RedirectResponse
    {
        Gate::authorize('brand.write');
        $data = $this->validateAt($request, route('brands.index'), ['name' => ['required', 'string', 'max:160'], 'website' => ['nullable', 'url:http,https', 'max:2048'], 'theme' => ['required', Rule::in(['neutral', 'pyro', 'confectionery'])]]);
        $this->checkWebsite($data['website'] ?? null);
        $ctx = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($ctx, $data, $limits): void {
            [$org, $member] = $this->lockAdmin($ctx);
            $name = trim($data['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Укажите название бренда.'])->redirectTo(route('brands.index'));
            }
            $count = DB::table('brands')->where('organization_id', $org->id)->where('status', 'active')->count();
            try {
                $limits->assertWithin($ctx, 'brands.max', (string) ($count + 1));
            } catch (ValidationException $error) {
                throw $error->redirectTo(route('brands.index'));
            }
            $brand = Brand::create(['name' => $name, 'website' => $data['website'] ?? null, 'theme' => $data['theme'], 'settings' => []]);
            if ($member->brand_ids !== []) {
                $member->forceFill(['brand_ids' => [...$member->brand_ids, $brand->id]])->save();
            }
            $this->audit($ctx, 'brand.created', 'brand', $brand->id, ['theme' => $brand->theme]);
        });

        return redirect()->route('brands.index');
    }

    public function archiveBrand(Request $request, string $brand): RedirectResponse
    {
        Gate::authorize('brand.write');
        $data = $this->validateAt($request, route('brands.index'), ['confirm_name' => ['required', 'string', 'max:160']]);
        $ctx = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($ctx, $data, $brand): void {
            [$org, $member] = $this->lockAdmin($ctx);
            $target = $this->target($org->id, $brand, $member, true);
            if ($target->status !== 'active') {
                throw ValidationException::withMessages(['brand' => 'Бренд уже находится в архиве.'])->redirectTo(route('brands.index'));
            }
            if (! hash_equals($target->name, $data['confirm_name'])) {
                throw ValidationException::withMessages(['confirm_name' => 'Название бренда не совпадает.'])->redirectTo(route('brands.index'));
            }
            if (DB::table('brands')->where('organization_id', $org->id)->where('status', 'active')->count() <= 1) {
                throw ValidationException::withMessages(['brand' => 'Последний активный бренд нельзя архивировать.'])->redirectTo(route('brands.index'));
            }
            if ($ctx->brandId === $target->id && ! Brand::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'active')->where('id', '!=', $target->id)->get()->contains(fn ($candidate) => $member->permitsBrand($candidate->id))) {
                throw ValidationException::withMessages(['brand' => 'Нужен другой активный бренд, к которому у вас есть доступ.'])->redirectTo(route('brands.index'));
            }
            DB::table('brands')->where('id', $target->id)->where('organization_id', $org->id)->update(['status' => 'archived', 'updated_at' => now()]);
            $this->audit($ctx, 'brand.archived', 'brand', $target->id);
        });
        if ($ctx->brandId === $brand) {
            $request->session()->forget('brand_id');

            return redirect()->route('auth.ready');
        }

        return redirect()->route('brands.index');
    }

    public function modules(Request $request, string $brand, ModuleEntitlements $modules, PlanLimits $limits, UsageReader $usage): Response
    {
        Gate::authorize('brand.write');
        $ctx = app(TenantContext::class);
        $target = $this->target($ctx->organizationId, $brand, $request->attributes->get('tenant.membership'));
        $scoped = $this->forBrand($ctx, $target->id);
        $items = [];
        foreach (['seller', 'vision'] as $module) {
            $limit = $limits->get($ctx, 'modules.'.$module);
            $metric = $module === 'seller' ? 'seller.dialogues' : 'vision.measurements';
            $periodStart = $module === 'seller' ? CarbonImmutable::now('UTC')->startOfMonth() : CarbonImmutable::now('UTC')->startOfWeek();
            $measurement = ['value' => null, 'unit' => '', 'status' => 'unavailable'];
            if ($target->status === 'active') {
                $measurement = app(TenantContextRunner::class)->run($scoped, fn () => $usage->read($scoped, new UsageQuery($periodStart, CarbonImmutable::now('UTC'), [$metric]))->metrics[$metric]);
            }
            $quota = $limits->get($ctx, $module === 'seller' ? 'seller.dialogs_month' : 'vision.runs_per_week');
            $items[$module] = ['usage' => $measurement, 'quota' => ['value' => $quota->value, 'configured' => $quota->configured, 'unit' => $quota->unit], 'enabled' => $modules->enabled($scoped, $module), 'configured_enabled' => (bool) (DB::table('brand_modules')->where('brand_id', $target->id)->where('organization_id', $ctx->organizationId)->where('module', $module)->value('enabled') ?? false), 'allowed' => $modules->allowedByPlan($scoped, $module), 'limit' => ['value' => $limit->value, 'configured' => $limit->configured, 'unit' => $limit->unit]];
        }

        return Inertia::render('Shell/Organizations/Modules', ['targetBrand' => ['id' => $target->id, 'name' => $target->name, 'status' => $target->status], 'items' => $items]);
    }

    public function updateModule(Request $request, string $brand, string $module, ModuleEntitlements $modules): RedirectResponse
    {
        Gate::authorize('brand.write');
        abort_unless(in_array($module, ['seller', 'vision'], true), 404);
        $data = $this->validateAt($request, route('brands.modules', $brand), ['enabled' => ['required', 'boolean']]);
        $ctx = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($ctx, $brand, $module, $data, $modules): void {
            [$org, $member] = $this->lockAdmin($ctx);
            $target = $this->target($org->id, $brand, $member, true);
            abort_unless($target->status === 'active', 404);
            $scoped = $this->forBrand($ctx, $target->id);
            if ($data['enabled'] && ! $modules->allowedByPlan($scoped, $module)) {
                throw ValidationException::withMessages(['enabled' => 'Модуль недоступен по текущему тарифу.'])->redirectTo(route('brands.modules', $brand));
            }
            $row = DB::table('brand_modules')->where('brand_id', $target->id)->where('module', $module)->lockForUpdate()->first();
            if ($row && (bool) $row->enabled === (bool) $data['enabled']) {
                return;
            }
            DB::table('brand_modules')->updateOrInsert(['brand_id' => $target->id, 'module' => $module], ['id' => $row->id ?? (string) Str::ulid(), 'organization_id' => $org->id, 'enabled' => (bool) $data['enabled'], 'created_at' => $row->created_at ?? now(), 'updated_at' => now()]);
            $this->audit($ctx, 'brand.module.changed', 'brand', $target->id, ['module' => $module, 'enabled' => (bool) $data['enabled']]);
        });

        return redirect()->route('brands.modules', $brand);
    }

    public function logo(Request $request, string $brand): HttpResponse
    {
        Gate::authorize('brand.read');
        $ctx = app(TenantContext::class);
        $target = $this->target($ctx->organizationId, $brand, $request->attributes->get('tenant.membership'));
        $path = $target->settings['logo_path'] ?? null;
        $prefix = 'organizations/'.preg_quote($ctx->organizationId, '~').'/onboarding/logos/';
        abort_unless(is_string($path) && preg_match('~^'.$prefix.'[a-f0-9]{32}\\.png$~D', $path) && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function lockAdmin(TenantContext $ctx): array
    {
        $org = Organization::whereKey($ctx->organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $member = Membership::withoutGlobalScopes()->whereKey($ctx->membershipId)->where('organization_id', $org->id)->where('user_id', $ctx->actorId)->where('status', 'active')->whereIn('role', ['owner', 'admin'])->lockForUpdate()->first();
        abort_unless($member, 403);
        $this->assertActiveBrandAccess($ctx, $member, $org->id);

        return [$org, $member];
    }

    private function assertActiveBrandAccess(TenantContext $ctx, Membership $member, string $organizationId): void
    {
        abort_unless($ctx->brandId && $member->permitsBrand($ctx->brandId) && DB::table('brands')->where('id', $ctx->brandId)->where('organization_id', $organizationId)->where('status', 'active')->exists(), 403);
    }

    private function target(string $org, string $id, Membership $member, bool $lock = false): Brand
    {
        abort_unless(Str::isUlid($id) && $member->permitsBrand($id), 404);
        $query = Brand::withoutGlobalScopes()->where('organization_id', $org)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? abort(404);
    }

    private function forBrand(TenantContext $ctx, string $id): TenantContext
    {
        return new TenantContext($ctx->organizationId, $id, $ctx->actorId, $ctx->membershipId, $ctx->source);
    }

    private function audit(TenantContext $ctx, string $action, string $type, string $id, ?array $after = null): void
    {
        app(AuditWriter::class)->record($ctx, new AuditEntry($action, $type, $id, after: $after));
    }

    private function validateAt(Request $request, string $destination, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo($destination);
        }

        return $validator->validated();
    }

    private function checkWebsite(?string $url): void
    {
        if ($url && (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
            throw ValidationException::withMessages(['website' => 'Адрес сайта не должен содержать логин или пароль.'])->redirectTo(route('brands.index'));
        }
    }
}
