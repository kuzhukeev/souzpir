<?php

namespace App\Vision\Site\Http;

use App\Models\Membership;
use App\Shell\Brands\BrandDomain;
use App\Tenancy\TenantContext;
use App\Vision\Actions\RecommendationLink;
use App\Vision\Core\Profiles\ProfileAccess;
use App\Vision\Site\AuditRun;
use App\Vision\Site\History;
use App\Vision\Site\Jobs\AuditSiteJob;
use App\Vision\Site\SiteAccess;
use App\Vision\Site\SiteAction;
use App\Vision\Site\SiteOperations;
use App\Vision\SiteAudit\SiteSafety;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SiteController
{
    public function __construct(private SiteAccess $access, private SiteOperations $operations, private History $history) {}

    public function index(Request $request): Response|JsonResponse
    {
        if ($request->query('quiz_status') === '1') {
            return $this->quizStatus($request);
        }
        $tenant = app(TenantContext::class);
        $props = DB::transaction(function () use ($tenant, $request): array {
            $this->access->lock($tenant, false, 'vision.report.read');
            $members = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)
                ->where('status', 'active')->with('user:id,name')->orderBy('id')->get()
                ->filter(fn (Membership $member): bool => $member->permitsBrand($tenant->brandId))
                ->map(fn (Membership $member): array => ['id' => $member->id, 'name' => $member->user?->name ?? 'Участник'])
                ->values()->all();
            $actions = SiteAction::query()->orderByDesc('created_at')->get()->map(fn (SiteAction $action): array => [
                'id' => $action->id, 'title' => $action->title, 'description' => $action->description,
                'responsible' => $action->responsible, 'due_date' => $action->due_date?->format('Y-m-d'),
                'comment' => $action->comment, 'completed_at' => $action->completed_at?->format('Y-m-d'),
                'evidence_url' => $action->evidence_url, 'status' => $action->status,
                'created_at' => $action->created_at?->toIso8601String(),
            ])->all();

            $audits = AuditRun::query()->orderByDesc('created_at')->orderByDesc('id')->limit(2)->get();
            $current = $audits->first();
            $previous = $audits->get(1);
            $host = parse_url($current?->result['final_url'] ?? $current?->result['url'] ?? '', PHP_URL_HOST);
            $domain = is_string($host) ? BrandDomain::query()->where('host', mb_strtolower($host))->first(['host', 'status']) : null;
            $domainStatus = is_string($host) ? ['host' => $host, 'verified' => $domain?->status === 'verified'] : null;
            $history = $request->route('section') === 'history' ? $this->history->read($tenant) : ['achievements' => [], 'comparison' => null];
            $this->access->lock($tenant, false, 'vision.report.read');

            $brandSite = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('website');
            $progress = Cache::get(AuditSiteJob::key($tenant->organizationId, $tenant->brandId));
            if (is_array($progress)) {
                if (in_array($progress['stage'] ?? null, ['inspecting', 'analyzing', 'saving'], true) && is_numeric($progress['started_at_epoch'] ?? null)) {
                    $progress['elapsed_seconds'] = max(0, (int) floor(microtime(true) - (float) $progress['started_at_epoch']));
                }
                unset($progress['started_at_epoch']);
            }

            return ['brandSite' => $brandSite, 'domainStatus' => $domainStatus, 'audit' => $current?->result, 'auditDate' => $current?->created_at?->toIso8601String(),
                'previousAudit' => $previous?->result,
                'auditProgress' => $progress,
                'actions' => $actions, 'history' => array_values(array_filter($actions, fn (array $a): bool => $a['status'] === 'awaiting_measurement')),
                'members' => $members, 'canManage' => $this->access->can('vision.actions.write'), ...$history];
        });

        return Inertia::render('Vision/Site/Index', ['section' => $request->route('section'), ...$props]);
    }

    private function quizStatus(Request $request): JsonResponse
    {
        return app(ProfileAccess::class)->run($request, function (TenantContext $tenant): JsonResponse {
            Gate::authorize('vision.settings.write');
            $website = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('website');
            $progress = Cache::get(AuditSiteJob::key($tenant->organizationId, $tenant->brandId), []);
            $stage = is_string($website) && $website !== '' && is_array($progress) && ($progress['url'] ?? null) === $website ? ($progress['stage'] ?? null) : null;
            $stage = in_array($stage, ['queued', 'inspecting', 'analyzing', 'saving', 'complete', 'failed'], true) ? $stage : null;
            $raw = DB::table('audit_runs')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->orderByDesc('created_at')->orderByDesc('id')->value('result');
            $audit = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_string($website) || ! is_array($audit) || ($audit['url'] ?? null) !== $website) {
                $audit = [];
            }

            return response()->json(['website' => $website, 'stage' => $stage, 'readiness' => $audit['readiness'] ?? null,
                'pages' => $audit['crawled_pages'] ?? null])->header('Cache-Control', 'private, no-store');
        });
    }

    public function audit(Request $request): RedirectResponse
    {
        $tenant = app(TenantContext::class);
        $this->operations->authorize($tenant, $request);
        $validator = Validator::make($request->all(), ['url' => ['required', 'string', 'max:2048', 'url:http,https']]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $data = $validator->validated();
        try {
            SiteSafety::noQuery($data['url']);
            $parts = parse_url($data['url']);
            if (isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            return back()->withErrors(['url' => 'Укажите публичный адрес без параметров и учётных данных.']);
        }
        $error = $this->operations->runAudit($tenant, $request, $data['url']);
        if ($error !== null) {
            return back()->withErrors(['url' => $error]);
        }

        return $request->header('X-Quiz-Origin') === '1'
            ? redirect()->route('vision.quiz.show', ['step' => 1])
            : redirect()->route('vision.site.overview');
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app(TenantContext::class);
        $this->operations->authorize($tenant, $request);
        $data = $this->validated($request, false);
        DB::transaction(function () use ($tenant, $request, $data): void {
            $id = $this->operations->create($tenant, $request, array_diff_key($data, array_flip(['report_id', 'recommendation_index'])));
            if (isset($data['report_id'], $data['recommendation_index'])) {
                app(RecommendationLink::class)->attach($tenant, $request, $id, $data['report_id'], (int) $data['recommendation_index']);
            }
        });

        return redirect()->route('vision.site.actions');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = app(TenantContext::class);
        // Hide foreign IDs before checking this actor's ability.
        SiteAction::query()->whereKey($id)->firstOrFail();
        $this->operations->authorize($tenant, $request);
        $this->operations->update($tenant, $request, $id, $this->validated($request, true));

        return redirect()->route('vision.site.actions');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $tenant = app(TenantContext::class);
        $this->operations->delete($tenant, $request, $id);

        return redirect()->route('vision.site.actions');
    }

    private function validated(Request $request, bool $update): array
    {
        $presence = $update ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$presence, 'required', 'string', 'max:255'],
            'report_id' => [$update ? 'prohibited' : 'required_with:recommendation_index', 'ulid'],
            'recommendation_index' => [$update ? 'prohibited' : 'required_with:report_id', 'integer', 'min:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'responsible' => ['sometimes', 'nullable', 'ulid'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'completed_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'evidence_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'status' => ['sometimes', Rule::in($update ? ['open', 'awaiting_measurement'] : ['open'])],
        ]);
        if (isset($data['title']) && trim($data['title']) === '') {
            throw ValidationException::withMessages(['title' => 'Укажите название действия.']);
        }
        if (! $update && (array_key_exists('completed_at', $data) || array_key_exists('evidence_url', $data))) {
            throw ValidationException::withMessages(['status' => 'Сначала создайте действие.']);
        }

        return $data;
    }
}
