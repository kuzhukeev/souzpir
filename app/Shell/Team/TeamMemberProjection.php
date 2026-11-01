<?php

namespace App\Shell\Team;

use App\Models\Brand;
use App\Models\Membership;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** A single disclosure rule for the list and the member detail page. */
final class TeamMemberProjection
{
    /** @var array<string, list<string>> */
    private array $modulesByBrand = [];

    private function __construct(private readonly Collection $brands, private readonly mixed $labels, private readonly bool $allInvitations) {}

    public static function forCurrentActor(): self
    {
        $context = app(TenantContext::class);
        $actor = Membership::query()->whereKey($context->membershipId)->where('user_id', $context->actorId)->where('status', 'active')->firstOrFail();
        $brands = Brand::withoutGlobalScopes()->where('organization_id', $context->organizationId)->where('status', 'active')
            ->orderBy('name')->orderBy('id')->get(['id', 'name'])->filter(fn (Brand $brand) => $actor->permitsBrand($brand->id))->values();

        $allInvitations = in_array($actor->role, ['owner', 'admin'], true) && $actor->brand_ids === [];

        return new self($brands, config('permissions.labels', []), $allInvitations);
    }

    public function project(Membership $membership): array
    {
        $grant = $this->grant($membership->role, $membership->settings, $membership->brand_ids);

        return [
            'id' => $membership->id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'role' => $grant['role'],
            'role_label' => $grant['role_label'],
            'status' => $membership->status,
            'brands' => array_map(fn (array $brand) => [...$brand, 'modules' => $this->enabledModules($brand['id'])], $grant['brands']),
        ];
    }

    /** @return list<string> */
    private function enabledModules(string $brandId): array
    {
        if (! array_key_exists($brandId, $this->modulesByBrand)) {
            $current = app(TenantContext::class);
            $context = new TenantContext($current->organizationId, $brandId, $current->actorId, $current->membershipId, $current->source);
            $entitlements = app(ModuleEntitlements::class);
            $this->modulesByBrand[$brandId] = array_values(array_filter(
                ['seller', 'vision'], fn (string $module) => $entitlements->enabled($context, $module),
            ));
        }

        return $this->modulesByBrand[$brandId];
    }

    /** Restricted actors see only invitations with at least one common active brand. */
    public function scopeInvitations(Builder $query): Builder
    {
        if ($this->allInvitations) {
            return $query;
        }
        $ids = $this->brands->pluck('id')->all();
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visible) use ($ids): void {
            foreach ($ids as $id) {
                $visible->orWhereJsonContains('brand_ids', $id);
            }
            $visible->orWhere(function (Builder $allBrands): void {
                $allBrands->where('role', 'admin')->where('brand_ids', '=', '[]');
            });
        });
    }

    /** Filter the same effective role that grant() exposes, before pagination. */
    public function scopeRole(Builder $query, string $role): Builder
    {
        if ($role === 'all') {
            return $query;
        }

        $baseRole = in_array($role, ['editor', 'manager'], true) ? 'member' : $role;
        $manager = $role === 'manager' ? 'true' : 'false';

        return $query->where('role', $baseRole)
            ->whereRaw("COALESCE(NULLIF(settings->'sales_manager', 'null'::jsonb), 'false'::jsonb) = ?::jsonb", [$manager]);
    }

    /** Only active brands available to the current actor can be disclosed or selected. */
    public function availableBrands(): array
    {
        return $this->brands->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name])->all();
    }

    public function permitsBrandFilter(string $brand): bool
    {
        return $brand === 'all' || $this->brands->contains(fn (Brand $item) => $item->id === $brand);
    }

    /** A full owner/admin grant also includes the selected brand. */
    public function scopeBrand(Builder $query, string $brand): Builder
    {
        if ($brand === 'all') {
            return $query;
        }

        return $query->where(function (Builder $matching) use ($brand): void {
            $matching->whereJsonContains('brand_ids', $brand)
                ->orWhere(function (Builder $allBrands): void {
                    $allBrands->whereIn('role', ['owner', 'admin'])->where('brand_ids', '=', '[]');
                });
        });
    }

    public function grant(string $baseRole, mixed $settings, mixed $brandIds): array
    {
        $manager = is_array($settings) ? ($settings['sales_manager'] ?? false) : null;
        $role = is_bool($manager) && (! $manager || $baseRole === 'member')
            ? match ($baseRole) {
                'owner', 'admin', 'viewer' => $baseRole,
                'member' => $manager ? 'manager' : 'editor',
                default => null,
            } : null;
        $ids = $brandIds;
        $validIds = is_array($ids) && array_is_list($ids);
        $ids = $validIds ? $ids : [];
        $all = $validIds && in_array($baseRole, ['owner', 'admin'], true) && $ids === [];
        $shared = $this->brands->filter(fn (Brand $brand) => $all || in_array($brand->id, $ids, true))
            ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name])->values()->all();

        return [
            'role' => $role,
            'role_label' => is_array($this->labels) && $role !== null && is_string($this->labels[$role] ?? null) ? $this->labels[$role] : null,
            'brands' => $shared,
        ];
    }

    public function canRevokeInvitation(mixed $brandIds, bool $canWrite): bool
    {
        if (! $canWrite || ! is_array($brandIds) || ! array_is_list($brandIds)) {
            return false;
        }
        if ($brandIds === []) {
            return $this->allInvitations;
        }
        $allowed = $this->brands->pluck('id')->all();
        $seen = [];
        foreach ($brandIds as $id) {
            if (! is_string($id) || in_array($id, $seen, true) || ! in_array($id, $allowed, true)) {
                return false;
            }
            $seen[] = $id;
        }

        return true;
    }
}
