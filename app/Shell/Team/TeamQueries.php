<?php

namespace App\Shell\Team;

use App\Models\Membership;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class TeamQueries
{
    public function __construct(private TeamAccess $access, private TeamAssignments $assignments) {}

    public function index(Request $request): array
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'], 'role' => ['nullable', Rule::in(array_keys(config('permissions.labels')))],
            'status' => ['nullable', Rule::in(['all', 'active', 'disabled', 'pending', 'expired', 'accepted', 'revoked'])],
            'brand_id' => ['nullable', 'ulid'], 'members_page' => ['nullable', 'integer', 'min:1'], 'invitations_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $actor = $this->access->actor();
        $brands = $this->access->brands($actor);
        $filters = ['q' => trim($filters['q'] ?? ''), 'role' => $filters['role'] ?? '', 'status' => $filters['status'] ?? 'all', 'brand_id' => $filters['brand_id'] ?? ''];
        abort_if($filters['brand_id'] !== '' && ! in_array($filters['brand_id'], array_column($brands, 'id'), true), 404);
        $members = Membership::query()->with('user');
        $invitations = Invitation::query();
        $this->filter($members, $actor, $filters, false);
        $this->filter($invitations, $actor, $filters, true);
        $members = $members->orderBy('created_at')->orderBy('id')->paginate(20, ['*'], 'members_page');
        $invitations = $invitations->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'invitations_page');
        $members->setCollection($members->getCollection()->map(fn ($member) => $this->member($member, $actor, $brands)));
        $invitations->setCollection($invitations->getCollection()->map(fn ($invitation) => $this->invitation($invitation, $actor, $brands)));

        return ['members' => $this->pagination($members), 'invitations' => $this->pagination($invitations),
            'brands' => $brands, 'filters' => $filters, 'can_manage' => Gate::allows('team.write'), 'can_grant_all_brands' => TeamRoles::allBrands($actor)];
    }

    public function show(Membership $member, Request $request): array
    {
        $actor = $this->access->actor();
        $brands = $this->access->brands($actor);
        $request->validate(['activity_page' => ['nullable', 'integer', 'min:1']]);
        $available = Gate::allows('journal.read');
        $activity = ['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0];
        if ($available) {
            $ctx = app(TenantContext::class);
            $events = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
                ->where('a.organization_id', $member->organization_id)->where('a.actor_id', $member->user_id)
                ->where(fn ($scope) => $scope->where('a.brand_id', $ctx->brandId)->orWhereNull('a.brand_id'))
                ->orderByDesc('a.created_at')->orderByDesc('a.id')
                ->paginate(20, ['a.id', 'a.action', 'a.created_at', 'u.name as actor_name'], 'activity_page');
            $labels = __('Shell/Team/messages.events');
            $events->setCollection($events->getCollection()->map(fn ($event) => ['id' => $event->id,
                'description' => $labels[$event->action] ?? __('Shell/Team/messages.activity_generic'),
                'created_at' => CarbonImmutable::parse($event->created_at)->toIso8601String(), 'actor_name' => $event->actor_name]));
            $activity = $this->pagination($events);
        }

        return ['member' => $this->member($member->load('user'), $actor, $brands), 'brands' => $brands,
            'can_manage' => Gate::allows('team.write'), 'can_grant_all_brands' => TeamRoles::allBrands($actor),
            'activity_available' => $available, 'activity' => $activity, 'assignments' => $this->assignments->currentBrand($member)];
    }

    public function member(Membership $member, Membership $actor, array $brands): array
    {
        $ids = array_column($brands, 'id');
        $visibleIds = array_values(array_intersect($member->brand_ids, $ids));
        $editable = $member->status === 'active' && $this->access->controls($member, $actor) && Gate::allows('update', $member);

        return ['id' => $member->id, 'user_id' => $member->user_id, 'name' => $member->user->name, 'email' => $member->user->email,
            'phone' => $member->user->phone, 'role' => TeamRoles::name($member), 'status' => $member->status,
            'brand_ids' => $visibleIds, 'all_brands' => TeamRoles::allBrands($member),
            'has_hidden_brands' => array_diff($member->brand_ids, $ids) !== [] || (TeamRoles::allBrands($member) && ! TeamRoles::allBrands($actor)),
            'is_self' => $member->user_id === $actor->user_id, 'is_owner' => $member->role === 'owner',
            'can_edit' => $editable, 'can_disable' => $editable, 'joined_at' => $member->created_at?->toIso8601String(), 'last_login_at' => null];
    }

    public function invitation(Invitation $invitation, Membership $actor, array $brands): array
    {
        $status = $invitation->accepted_at ? 'accepted' : ($invitation->revoked_at ? 'revoked' : ($invitation->expires_at->isFuture() ? 'pending' : 'expired'));
        $manageable = Gate::allows('team.write') && $this->access->controls($invitation, $actor) && in_array($status, ['pending', 'expired'], true);
        $ids = array_column($brands, 'id');

        return ['id' => $invitation->id, 'email' => $invitation->email, 'role' => TeamRoles::name($invitation),
            'brand_ids' => array_values(array_intersect($invitation->brand_ids, $ids)), 'all_brands' => TeamRoles::allBrands($invitation),
            'has_hidden_brands' => array_diff($invitation->brand_ids, $ids) !== [] || (TeamRoles::allBrands($invitation) && ! TeamRoles::allBrands($actor)),
            'status' => $status, 'created_at' => $invitation->created_at->toIso8601String(), 'expires_at' => $invitation->expires_at->toIso8601String(),
            'can_resend' => $manageable, 'can_revoke' => $manageable];
    }

    private function filter(Builder $query, Membership $actor, array $filters, bool $invitations): void
    {
        if (! TeamRoles::allBrands($actor)) {
            $this->matchingBrands($query, $actor->brand_ids);
        }
        if ($filters['brand_id'] !== '') {
            $this->matchingBrands($query, [$filters['brand_id']]);
        }
        if ($filters['role'] !== '') {
            $role = $filters['role'];
            $query->where('role', in_array($role, ['editor', 'manager'], true) ? 'member' : $role);
            if (in_array($role, ['editor', 'manager'], true)) {
                $query->whereRaw("COALESCE((settings->>'sales_manager')::boolean, false) = ?", [$role === 'manager']);
            }
        }
        if ($filters['q'] !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filters['q']).'%';
            if ($invitations) {
                $query->where('email', 'ilike', $needle);
            } else {
                $query->whereHas('user', fn ($users) => $users->where(fn ($where) => $where->where('name', 'ilike', $needle)->orWhere('email', 'ilike', $needle)));
            }
        }
        $status = $filters['status'];
        if ($status === 'all') {
            return;
        }
        if (! $invitations) {
            in_array($status, ['active', 'disabled'], true) ? $query->where('status', $status) : $query->whereRaw('1 = 0');

            return;
        }
        if (in_array($status, ['active', 'disabled'], true)) {
            $query->whereRaw('1 = 0');
        } elseif ($status === 'accepted') {
            $query->whereNotNull('accepted_at');
        } elseif ($status === 'revoked') {
            $query->whereNull('accepted_at')->whereNotNull('revoked_at');
        } else {
            $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', $status === 'pending' ? '>' : '<=', now());
        }
    }

    private function matchingBrands(Builder $query, array $ids): void
    {
        $query->where(function ($where) use ($ids): void {
            $where->where(fn ($all) => $all->whereIn('role', ['owner', 'admin'])->whereJsonLength('brand_ids', 0));
            foreach ($ids as $id) {
                $where->orWhereJsonContains('brand_ids', $id);
            }
        });
    }

    private function pagination($paginator): array
    {
        return ['data' => $paginator->items(), 'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(), 'total' => $paginator->total()];
    }
}
