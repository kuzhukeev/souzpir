<?php

namespace App\Shell\Team\Http;

use App\Models\Brand;
use App\Models\Membership;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TeamMemberUpdateController
{
    public function __invoke(Request $request, string $membership): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'editor', 'manager', 'viewer'])],
            'brand_ids' => ['present', 'array'],
            'brand_ids.*' => ['required', 'ulid', 'distinct'],
            'status' => ['required', Rule::in(['active', 'disabled'])],
        ]);
        $ctx = app(TenantContext::class);
        Gate::authorize('team.write');
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $ctx, $membership, $data): void {
            $expected = $request->attributes->get('tenant.brand_revision');
            $selection = app(SessionBrandSelection::class)->current($request);
            $provided = $request->header('X-Brand-Revision');
            abort_unless(is_string($expected) && (string) $selection->brand_revision === $expected && $selection->selected_organization_id === $ctx->organizationId && $selection->selected_brand_id === $ctx->brandId && is_string($provided) && hash_equals(app(SessionBrandSelection::class)->profileRevision($request, $expected), $provided), 409);
            $member = Membership::query()->findOrFail($membership);
            Gate::authorize('update', $member);
            $actor = Membership::query()->whereKey($ctx->membershipId)->where('user_id', $ctx->actorId)->where('status', 'active')->firstOrFail();
            $all = in_array($actor->role, ['owner', 'admin'], true) && $actor->brand_ids === [];
            abort_unless($all || ($member->brand_ids !== [] && array_diff($member->brand_ids, $actor->brand_ids) === []), 403);
            abort_unless($member->status === 'active', 403);
            $ids = $data['brand_ids'];
            if (($ids === [] && ($data['role'] !== 'admin' || ! $all)) || (! $all && array_diff($ids, $actor->brand_ids)) ||
                Brand::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)->where('status', 'active')->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['brand_ids' => 'Выберите доступные бренды.']);
            }
            $role = in_array($data['role'], ['editor', 'manager'], true) ? 'member' : $data['role'];
            $manager = $data['role'] === 'manager';
            $changed = $member->role !== $role || ($member->settings['sales_manager'] ?? false) !== $manager ||
                array_diff($member->brand_ids, $ids) || array_diff($ids, $member->brand_ids) || $member->status !== $data['status'];
            if (! $changed) {
                return;
            }
            $seller = fn (string $table) => DB::table($table)->where('organization_id', $ctx->organizationId)->where('assigned_user_id', $member->user_id);
            if ($seller('conversations')->whereNotIn('stage', ['lost', 'spam', 'closed'])->exists() ||
                $seller('seller_tasks')->where('status', 'new')->exists() ||
                DB::table('actions')->where('organization_id', $ctx->organizationId)->where('responsible', $member->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['member' => 'Сначала передайте задачи']);
            }
            $before = ['role' => $member->role, 'brand_ids' => $member->brand_ids, 'status' => $member->status];
            $member->fill(['role' => $role, 'brand_ids' => $ids, 'status' => $data['status'], 'settings' => [...$member->settings, 'sales_manager' => $manager]])->save();
            if ($data['status'] === 'disabled') {
                $user = $member->user;
                $user->forceFill(['auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            }
            app(AuditWriter::class)->record($ctx, new AuditEntry('team.member.updated', 'membership', $member->id, $before,
                ['role' => $role, 'brand_ids' => $ids, 'status' => $data['status'], 'sales_manager' => $manager]));
        });

        return redirect()->route('team.show', $membership);
    }
}
