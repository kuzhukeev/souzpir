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
use App\Vision\Reports\ReportData;
use App\Vision\Settings\Trends;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class DashboardController
{
    private function data(User $user, TenantContext $tenant, bool $allNotifications = false, int $days = 7): array
    {
        $member = Membership::withoutGlobalScopes()->where('id', $tenant->membershipId)->where('organization_id', $tenant->organizationId)->where('user_id', $user->id)->where('status', 'active')->first();
        abort_unless($member && $tenant->brandId && $member->permitsBrand($tenant->brandId), 403);
        $sellerEnabled = app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId);
        $seller = Gate::forUser($user)->allows('seller.dialogs.read') && $sellerEnabled
            ? app(DashboardQuery::class)->read($tenant, $days) : null;
        if ($seller !== null) {
            $hidden = DB::table('dashboard_attention_snoozes')->where(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'user_id' => $user->id])->where('until_at', '>', now())->pluck('kind')->all();
            $seller['attention'] = array_values(array_filter($seller['attention'], fn ($item) => ! in_array($item['kind'], $hidden, true)));
        }
        $vision = null;
        $nextMeasurement = null;
        $visionError = false;
        $canVision = Gate::forUser($user)->allows('vision.report.read') && app(ModuleEntitlements::class)->enabled($tenant, 'vision');
        if ($canVision) {
            try {
                $vision = collect(app(Trends::class)->read($tenant))->last();
                if ($vision) {
                    $reportData = app(ReportData::class)->read($tenant, $vision['id']);
                    $report = $reportData['report'];
                    $vision['questions'] = $report['questions'] ?? null;
                    $vision['mentions'] = null;
                    $vision['measurement_at'] = collect($reportData['reports'])->firstWhere('id', $vision['id'])['measurement_at'] ?? null;
                }
                $nextMeasurement = DB::table('monitoring_schedules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('enabled', true)->value('next_run_at');
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
        $destinations = [
            ['id' => 'company', 'label' => 'Компания и бренды', 'description' => 'Данные компании и доступные бренды', 'permission' => 'org.settings.write', 'route' => 'organization.show'],
            ['id' => 'team', 'label' => 'Команда', 'description' => 'Участники и приглашения', 'permission' => 'team.read', 'route' => 'team.index'],
            ['id' => 'journal', 'label' => 'Журнал', 'description' => 'Действия в кабинете', 'permission' => 'journal.read', 'route' => 'journal.index'],
            ['id' => 'profile', 'label' => 'Личный профиль', 'description' => 'Данные и безопасность вашей учётной записи', 'permission' => 'profile.own', 'route' => 'profile.show'],
        ];
        $homeLinks = [];
        foreach ($destinations as $destination) {
            if (Route::has($destination['route']) && Gate::forUser($user)->allows($destination['permission'])) {
                $homeLinks[] = ['id' => $destination['id'], 'label' => $destination['label'], 'description' => $destination['description'], 'href' => route($destination['route'], absolute: false)];
            }
        }
        $feed = $this->notifications($user, $tenant, $member, $sellerEnabled, $allNotifications);

        return ['homeLinks' => $homeLinks, 'seller' => $seller, 'days' => $days, 'vision' => $vision, 'nextMeasurement' => $nextMeasurement, 'canVision' => $canVision, 'visionError' => $visionError, 'usage' => $usage, 'usageError' => $usageError, ...$feed];
    }

    private function notifications(User $user, TenantContext $tenant, Membership $member, bool $sellerEnabled, bool $all = false): array
    {
        $policy = app(RecipientPolicy::class);
        $permits = fn (string $key) => (! str_starts_with($key, 'seller.') || $sellerEnabled) && $policy->permits($member, $tenant->brandId, $key);
        $scope = fn ($query) => $query->where('n.organization_id', $tenant->organizationId)->where('n.brand_id', $tenant->brandId)->where('n.user_id', $user->id);
        $items = $scope(DB::table('cabinet_notifications as n'))->orderByDesc('n.created_at')->get(['n.id', 'n.event_key', 'n.title', 'n.read_at', 'n.created_at'])
            ->filter(fn ($n) => $permits($n->event_key))
            ->map(fn ($n) => ['id' => $n->id, 'kind' => 'event', 'group' => str_starts_with($n->event_key, 'seller.') ? 'seller' : (str_starts_with($n->event_key, 'vision.') ? 'vision' : 'shell'), 'title' => $n->title, 'read' => $n->read_at !== null, 'created_at' => $n->created_at]);
        $digestReads = DB::table('dashboard_digest_reads as r')->join('seller_digest_batches as b', 'b.id', '=', 'r.batch_id')->where('b.organization_id', $tenant->organizationId)->where('b.brand_id', $tenant->brandId)->where('b.user_id', $user->id)->pluck('r.batch_id')->all();
        $digests = $scope(DB::table('seller_digest_cabinet_notifications as n')->join('seller_digest_batches as b', 'b.id', '=', 'n.batch_id'))
            ->where('b.organization_id', $tenant->organizationId)->where('b.brand_id', $tenant->brandId)->where('b.user_id', $user->id)->where('b.status', 'sent')
            ->orderByDesc('n.created_at')->get(['n.batch_id', 'n.titles', 'n.event_keys', 'n.created_at'])
            ->map(function ($n) use ($permits, $digestReads): ?array {
                $titles = json_decode($n->titles, true) ?: [];
                $keys = json_decode($n->event_keys, true) ?: [];
                $visible = [];
                $groups = [];
                foreach ($keys as $i => $key) {
                    if (is_string($key) && isset($titles[$i]) && $permits($key)) {
                        $visible[] = $titles[$i];
                        $groups[] = str_starts_with($key, 'seller.') ? 'seller' : (str_starts_with($key, 'vision.') ? 'vision' : 'shell');
                    }
                }

                return $visible ? ['id' => $n->batch_id, 'kind' => 'digest', 'group' => $groups[0], 'groups' => array_values(array_unique($groups)), 'title' => implode(', ', $visible), 'read' => in_array($n->batch_id, $digestReads, true), 'created_at' => $n->created_at] : null;
            })->filter();

        $combined = $items->concat($digests)->sortByDesc('created_at');

        return ['items' => ($all ? $combined : $combined->take(100))->values(), 'unread' => $items->where('read', false)->count() + $digests->where('read', false)->count()];
    }

    public function unread(Request $request): ?int
    {
        $user = $request->user();
        $organization = $request->attributes->get('tenant.organization');
        $brand = $request->attributes->get('tenant.brand');
        if (! $user || ! $organization || ! $brand) {
            return null;
        }
        $member = Membership::withoutGlobalScopes()->where('organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->first();
        if (! $member || ! $member->permitsBrand($brand->id)) {
            return null;
        }
        $tenant = new TenantContext($organization->id, $brand->id, $user->id, $member->id);
        $enabled = app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId);

        return $this->notifications($user, $tenant, $member, $enabled)['unread'];
    }

    public function index(Request $request): mixed
    {
        $validated = $request->validate(['days' => ['sometimes', 'integer', Rule::in([7, 30, 90])]]);
        $days = (int) ($validated['days'] ?? 7);
        $data = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($days): array {
            Gate::forUser($user)->authorize('profile.own');

            return $this->data($user, $tenant, days: $days);
        });

        return Inertia::render('Shell/Dashboard/Index', $data);
    }

    public function attention(Request $request): mixed
    {
        $data = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant): array {
            Gate::forUser($user)->authorize('profile.own');

            return $this->data($user, $tenant);
        });

        return Inertia::render('Shell/Dashboard/Attention', ['seller' => $data['seller']]);
    }

    public function snoozeAttention(Request $request, string $kind): mixed
    {
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($kind): void {
            Gate::forUser($user)->authorize('profile.own');
            $seller = $this->data($user, $tenant)['seller'];
            abort_unless($seller && collect($seller['attention'])->contains(fn ($item) => $item['kind'] === $kind), 404);
            $where = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'user_id' => $user->id, 'kind' => $kind];
            $existing = DB::table('dashboard_attention_snoozes')->where($where)->first();
            if ($existing) {
                DB::table('dashboard_attention_snoozes')->where('id', $existing->id)->update(['until_at' => now()->addDays(7), 'updated_at' => now()]);
            } else {
                DB::table('dashboard_attention_snoozes')->insert(['id' => (string) Str::ulid(), ...$where, 'until_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back();
    }

    public function notificationCenter(Request $request): mixed
    {
        $data = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant): array {
            Gate::forUser($user)->authorize('profile.own');

            return $this->data($user, $tenant, true);
        });

        return Inertia::render('Shell/Notifications/Index', ['items' => $data['items'], 'unread' => $data['unread']]);
    }

    public function read(Request $request, string $kind, string $id): mixed
    {
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($id, $kind): void {
            Gate::forUser($user)->authorize('profile.own');
            $allowed = collect($this->data($user, $tenant, true)['items'])->contains(fn ($item) => $item['kind'] === $kind && $item['id'] === $id);
            abort_unless($allowed, 404);
            if ($kind === 'digest') {
                DB::table('dashboard_digest_reads')->updateOrInsert(['batch_id' => $id], ['read_at' => now()]);
            } else {
                DB::table('cabinet_notifications')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('user_id', $user->id)->where('id', $id)->update(['read_at' => now()]);
            }
        });

        return back();
    }

    public function readAll(Request $request): mixed
    {
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant): void {
            Gate::forUser($user)->authorize('profile.own');
            $member = Membership::withoutGlobalScopes()->where('id', $tenant->membershipId)->where('organization_id', $tenant->organizationId)->where('user_id', $user->id)->where('status', 'active')->first();
            abort_unless($member && $tenant->brandId && $member->permitsBrand($tenant->brandId), 403);
            $enabled = app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId);
            $items = collect($this->notifications($user, $tenant, $member, $enabled, true)['items'])->where('read', false);
            DB::transaction(function () use ($items, $user, $tenant): void {
                foreach ($items as $item) {
                    if ($item['kind'] === 'digest') {
                        DB::table('dashboard_digest_reads')->updateOrInsert(['batch_id' => $item['id']], ['read_at' => now()]);
                    } else {
                        DB::table('cabinet_notifications')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('user_id', $user->id)->where('id', $item['id'])->update(['read_at' => now()]);
                    }
                }
            });
        });

        return back();
    }
}
