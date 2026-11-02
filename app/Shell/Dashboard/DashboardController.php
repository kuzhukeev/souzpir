<?php

namespace App\Shell\Dashboard;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Dashboard\DashboardQuery;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Notifications\RecipientPolicy;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Vision\Settings\Trends;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class DashboardController
{
    private function data(User $user, TenantContext $tenant): array
    {
        $member = Membership::withoutGlobalScopes()->where('id', $tenant->membershipId)->where('organization_id', $tenant->organizationId)->where('user_id', $user->id)->where('status', 'active')->first();
        abort_unless($member && $tenant->brandId && $member->permitsBrand($tenant->brandId), 403);
        $sellerEnabled = app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId);
        $seller = Gate::forUser($user)->allows('seller.dialogs.read') && $sellerEnabled
            ? app(DashboardQuery::class)->read($tenant, 30) : null;
        $vision = null;
        $visionError = false;
        $canVision = Gate::forUser($user)->allows('vision.report.read') && app(ModuleEntitlements::class)->enabled($tenant, 'vision');
        if ($canVision) {
            try {
                $vision = collect(app(Trends::class)->read($tenant))->last();
            } catch (\Throwable) {
                $visionError = true;
            }
        }
        $metrics = [];
        if ($seller !== null) {
            $metrics[] = 'seller.dialogues';
        }
        if ($canVision) {
            $metrics[] = 'vision.measurements';
        }
        $usage = [];
        $usageError = false;
        if ($metrics) {
            try {
                $now = CarbonImmutable::now('UTC');
                $usage = app(UsageReader::class)->read($tenant, new UsageQuery($now->startOfMonth(), $now, $metrics))->metrics;
            } catch (\Throwable) {
                $usageError = true;
            }
        }
        $policy = app(RecipientPolicy::class);
        $permits = fn (string $key) => (! str_starts_with($key, 'seller.') || $sellerEnabled) && $policy->permits($member, $tenant->brandId, $key);
        $scope = fn ($query) => $query->where('n.organization_id', $tenant->organizationId)->where('n.brand_id', $tenant->brandId)->where('n.user_id', $user->id);
        $items = $scope(DB::table('cabinet_notifications as n'))->orderByDesc('n.created_at')->get(['n.id', 'n.event_key', 'n.title', 'n.read_at', 'n.created_at'])
            ->filter(fn ($n) => $permits($n->event_key))
            ->map(fn ($n) => ['id' => $n->id, 'kind' => 'event', 'title' => $n->title, 'read' => $n->read_at !== null, 'created_at' => $n->created_at]);
        $digestReads = DB::table('dashboard_digest_reads as r')->join('seller_digest_batches as b', 'b.id', '=', 'r.batch_id')->where('b.organization_id', $tenant->organizationId)->where('b.brand_id', $tenant->brandId)->where('b.user_id', $user->id)->pluck('r.batch_id')->all();
        $digests = $scope(DB::table('seller_digest_cabinet_notifications as n')->join('seller_digest_batches as b', 'b.id', '=', 'n.batch_id'))
            ->where('b.organization_id', $tenant->organizationId)->where('b.brand_id', $tenant->brandId)->where('b.user_id', $user->id)->where('b.status', 'sent')
            ->orderByDesc('n.created_at')->get(['n.batch_id', 'n.titles', 'n.event_keys', 'n.created_at'])
            ->map(function ($n) use ($permits, $digestReads): ?array {
                $titles = json_decode($n->titles, true) ?: [];
                $keys = json_decode($n->event_keys, true) ?: [];
                $visible = [];
                foreach ($keys as $i => $key) {
                    if (is_string($key) && isset($titles[$i]) && $permits($key)) {
                        $visible[] = $titles[$i];
                    }
                }

                return $visible ? ['id' => $n->batch_id, 'kind' => 'digest', 'title' => implode(', ', $visible), 'read' => in_array($n->batch_id, $digestReads, true), 'created_at' => $n->created_at] : null;
            })->filter();

        return ['seller' => $seller, 'vision' => $vision, 'canVision' => $canVision, 'visionError' => $visionError, 'usage' => $usage, 'usageError' => $usageError, 'items' => $items->concat($digests)->sortByDesc('created_at')->take(100)->values(), 'unread' => $items->where('read', false)->count() + $digests->where('read', false)->count()];
    }

    public function index(Request $request): mixed
    {
        $data = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant): array {
            Gate::forUser($user)->authorize('profile.own');

            return $this->data($user, $tenant);
        });

        return Inertia::render('Shell/Dashboard/Index', $data);
    }

    public function read(Request $request, string $kind, string $id): mixed
    {
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($id, $kind): void {
            Gate::forUser($user)->authorize('profile.own');
            $allowed = collect($this->data($user, $tenant)['items'])->contains(fn ($item) => $item['kind'] === $kind && $item['id'] === $id);
            abort_unless($allowed, 404);
            if ($kind === 'digest') {
                DB::table('dashboard_digest_reads')->updateOrInsert(['batch_id' => $id], ['read_at' => now()]);
            } else {
                DB::table('cabinet_notifications')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('user_id', $user->id)->where('id', $id)->update(['read_at' => now()]);
            }
        });

        return back();
    }
}
