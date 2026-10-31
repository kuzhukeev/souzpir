<?php

namespace App\Shell\Team;

use App\Models\Brand;
use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/** A single disclosure rule for the list and the member detail page. */
final class TeamMemberProjection
{
    private function __construct(private readonly Collection $brands, private readonly mixed $labels) {}

    public static function forCurrentActor(): self
    {
        $context = app(TenantContext::class);
        $actor = Membership::query()->whereKey($context->membershipId)->where('user_id', $context->actorId)->where('status', 'active')->firstOrFail();
        $brands = Brand::withoutGlobalScopes()->where('organization_id', $context->organizationId)->where('status', 'active')
            ->orderBy('name')->orderBy('id')->get(['id', 'name'])->filter(fn (Brand $brand) => $actor->permitsBrand($brand->id))->values();

        return new self($brands, config('permissions.labels', []));
    }

    public function project(Membership $membership): array
    {
        $settings = $membership->settings;
        $manager = is_array($settings) ? ($settings['sales_manager'] ?? false) : null;
        $role = is_bool($manager) && (! $manager || $membership->role === 'member')
            ? match ($membership->role) {
                'owner', 'admin', 'viewer' => $membership->role,
                'member' => $manager ? 'manager' : 'editor',
                default => null,
            } : null;
        $ids = $membership->brand_ids;
        $validIds = is_array($ids) && array_is_list($ids);
        $ids = $validIds ? $ids : [];
        $all = $validIds && in_array($membership->role, ['owner', 'admin'], true) && $ids === [];
        $shared = $this->brands->filter(fn (Brand $brand) => $all || in_array($brand->id, $ids, true))
            ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name])->values()->all();

        return [
            'id' => $membership->id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'role' => $role,
            'role_label' => is_array($this->labels) && $role !== null && is_string($this->labels[$role] ?? null) ? $this->labels[$role] : null,
            'status' => $membership->status,
            'brands' => $shared,
        ];
    }
}
