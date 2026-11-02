<?php

namespace App\Seller\Knowledge\Releases;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class ReleasesController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function query(string $table, TenantContext $tenant): Builder
    {
        return DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'seller.knowledge.read', function (User $user, TenantContext $tenant): array {
            $releases = $this->query('knowledge_releases', $tenant)->whereIn('status', ['draft', 'published'])->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'status', 'version', 'published_at', 'updated_at']);
            $brand = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->first(['active_knowledge_release_id']);
            $draft = $releases->firstWhere('status', 'draft');
            $counts = [];
            try {
                $snapshot = app(KnowledgeRepository::class)->published($tenant)->toArray();
                foreach (['facts', 'intents', 'triggers', 'closed_topics'] as $key) {
                    $counts[$key] = $key === 'facts' ? array_sum(array_map(fn ($group) => count($group['facts']), $snapshot[$key])) : count($snapshot[$key]);
                }
            } catch (KnowledgeNotReady) {
            }
            $tasks = $this->query('seller_tasks', $tenant)->where('type', 'unknown_question')->where('status', 'new')->orderByDesc('created_at')->limit(50)->get(['id', 'payload', 'created_at']);
            $reviews = $this->query('message_reviews', $tenant)->where('type', 'wrong_fact')->where('status', 'new')->orderByDesc('created_at')->limit(50)->get(['id', 'note', 'better_text', 'created_at']);
            $handled = $this->query('audit_logs', $tenant)->where('action', 'knowledge.incoming.handled')->where('subject_type', 'evaluation')->pluck('subject_id');
            $evaluations = $this->query('seller_evaluations', $tenant)->whereNotIn('id', $handled)->whereRaw('jsonb_array_length(issues) > 0')->orderByDesc('created_at')->limit(50)->get(['id', 'issues', 'comment', 'created_at']);

            return ['releases' => $releases, 'activeId' => $brand?->active_knowledge_release_id, 'draft' => $draft, 'counts' => $counts,
                'tasks' => $tasks, 'reviews' => $reviews, 'evaluations' => $evaluations, 'canWrite' => Gate::forUser($user)->allows('seller.knowledge.write')];
        });

        return Inertia::render('Seller/Knowledge/Releases/Index', [...$data, 'status' => session('status')]);
    }

    public function draft(Request $request): mixed
    {
        $this->access($request, 'seller.knowledge.write', function (User $user, TenantContext $tenant): void {
            $repository = app(KnowledgeRepository::class);
            try {
                $data = $repository->published($tenant)->toArray();
            } catch (KnowledgeNotReady) {
                $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            }
            $repository->createDraft($tenant, $data);
        });

        return back()->with('status', 'Черновик создан.');
    }

    public function compare(Request $request): mixed
    {
        $input = $request->validate(['from' => ['required', 'ulid'], 'to' => ['required', 'ulid', 'different:from']]);
        $data = $this->access($request, 'seller.knowledge.read', function (User $user, TenantContext $tenant) use ($input): array {
            $repository = app(KnowledgeRepository::class);
            $before = $repository->forRelease($tenant, $input['from'], true);
            $after = $repository->forRelease($tenant, $input['to'], true);
            $releases = $this->query('knowledge_releases', $tenant)->whereIn('id', [$input['from'], $input['to']])->get(['id', 'status', 'version', 'published_at']);

            return ['from' => $releases->firstWhere('id', $input['from']), 'to' => $releases->firstWhere('id', $input['to']),
                'sections' => app(ReleaseComparison::class)->compare($before, $after)];
        });

        return Inertia::render('Seller/Knowledge/Releases/Compare', $data);
    }

    public function publish(Request $request, string $release): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->access($request, 'seller.knowledge.write', fn (User $user, TenantContext $tenant) => app(KnowledgeRepository::class)->publish($tenant, $release, (int) $input['version']));

        return back()->with('status', 'Знания опубликованы.');
    }

    public function rollback(Request $request, string $release): mixed
    {
        $this->access($request, 'seller.knowledge.write', fn (User $user, TenantContext $tenant) => app(KnowledgeRepository::class)->rollback($tenant, $release));

        return back()->with('status', 'Версия знаний восстановлена.');
    }
}
