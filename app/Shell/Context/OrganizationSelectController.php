<?php

namespace App\Shell\Context;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\User;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

final class OrganizationSelectController
{
    public function index(Request $request): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context, $brand, Membership $member, $organization): mixed {
            Gate::forUser($user)->authorize('profile.own');
            $brands = Brand::withoutGlobalScopes()->where('organization_id', $context->organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->filter(fn (Brand $item) => $member->permitsBrand($item->id))->map(fn (Brand $item) => ['id' => $item->id, 'name' => $item->name])->values()->all();
            $lastLogin = DB::table('member_last_logins')->where('user_id', $user->id)->value('last_login_at');
            $path = DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->value('logo_path');
            $logo = is_string($path) && $this->validPath($context->organizationId, $path) && Storage::disk('local')->exists($path);
            $role = match ($member->role) {
                'owner' => 'Владелец', 'admin' => 'Администратор',
                'member' => ($member->settings['sales_manager'] ?? false) ? 'Менеджер' : 'Редактор',
                default => 'Наблюдатель',
            };

            return Inertia::render('Shell/Context/OrganizationSelect', ['company' => ['name' => $organization->name, 'role' => $role,
                'brands' => $brands, 'lastLogin' => $lastLogin ? CarbonImmutable::parse($lastLogin)->toIso8601String() : null,
                'logoUrl' => $logo ? route('context.organization.logo', absolute: false) : null]]);
        });
    }

    public function logo(Request $request): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context): mixed {
            Gate::forUser($user)->authorize('profile.own');
            $path = DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->value('logo_path');
            abort_unless(is_string($path) && $this->validPath($context->organizationId, $path) && Storage::disk('local')->exists($path), 404);

            return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    private function validPath(string $organization, string $path): bool
    {
        return preg_match('~^organizations/'.preg_quote($organization, '~').'/onboarding/logos/[a-f0-9]{32}\\.png$~D', $path) === 1;
    }
}
