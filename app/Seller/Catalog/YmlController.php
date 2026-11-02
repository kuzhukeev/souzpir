<?php

namespace App\Seller\Catalog;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Vision\SiteAudit\PublicUrlPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class YmlController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['url' => ['required', 'string', 'max:2048'], 'interval_hours' => ['nullable', 'integer', 'in:1,6,24,168']]);
        try {
            $url = app(PublicUrlPolicy::class)->normalize($input['url']);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['url' => 'Укажите публичный HTTPS URL без параметров и учётных данных.']);
        }
        $this->access($request, function (User $user, TenantContext $tenant) use ($input, $url): void {
            app(YmlFeedStore::class)->write($tenant->organizationId, $tenant->brandId, ['url' => $url, 'interval_hours' => isset($input['interval_hours']) ? (int) $input['interval_hours'] : null,
                'user_id' => $user->id, 'last_run_at' => null]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.catalog.yml.configured', 'brand', $tenant->brandId, after: ['interval_hours' => $input['interval_hours'] ?? null]));
        });

        return back()->with('status', 'Источник YML сохранён.');
    }

    public function refresh(Request $request): mixed
    {
        [$tenant, $settings] = $this->access($request, function (User $user, TenantContext $tenant): array {
            $settings = app(YmlFeedStore::class)->read($tenant->organizationId, $tenant->brandId);
            if (! $settings) {
                throw ValidationException::withMessages(['url' => 'Сначала сохраните источник YML.']);
            }
            $settings['user_id'] = $user->id;

            return [$tenant, $settings];
        });
        $ok = app(YmlRefresh::class)->run($tenant, $settings);
        $settings['last_run_at'] = now()->toIso8601String();
        app(YmlFeedStore::class)->write($tenant->organizationId, $tenant->brandId, $settings);

        return $ok ? back()->with('status', 'Каталог YML обновлён.') : back()->withErrors(['url' => 'Не удалось обновить YML. Проверьте адрес и формат.']);
    }
}
