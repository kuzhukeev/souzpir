<?php

namespace App\Vision\Sources;

use App\Ai\CanonicalJson;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use App\Vision\Core\ReportPreparation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class SourcesController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(ModuleEntitlements::class)->enabled($tenant, 'vision'), 404);

            return $callback($user, $tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'vision.report.read', function (User $user, TenantContext $tenant): array {
            $snapshot = app(SnapshotView::class)->current($tenant);
            $presence = DB::table('vision_source_presence')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->pluck('presence', 'domain')->all();
            $questions = collect($snapshot['questions'] ?? [])->keyBy('id');
            $profiles = $snapshot ? $this->confirmedProfiles($tenant, $snapshot) : [];
            $descriptions = collect(data_get($snapshot, 'metrics.sources', []))->pluck('domain')->filter(fn ($domain) => is_string($domain))
                ->map(fn ($domain) => "Проверить присутствие бренда на площадке {$domain}.")->all();
            $actions = DB::table('actions')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->whereIn('description', $descriptions)->orderByDesc('updated_at')->get(['id', 'description', 'status', 'completed_at'])->unique('description')->keyBy('description');
            $citationAvailable = is_array(data_get($snapshot, 'metrics.slots'));
            $sources = collect(data_get($snapshot, 'metrics.sources', []))->map(function ($source) use ($presence, $snapshot, $questions, $profiles, $actions, $citationAvailable): array {
                $examples = [];
                $competitors = [];
                $models = [];
                $citations = $citationAvailable ? 0 : null;
                foreach (data_get($snapshot, 'metrics.slots', []) as $slot) {
                    if (! ($slot['valid'] ?? false)) {
                        continue;
                    }
                    foreach ($slot['sources'] ?? [] as $item) {
                        if (($item['domain'] ?? null) !== $source['domain']) {
                            continue;
                        }
                        if ($citations !== null) {
                            $citations++;
                        }
                        $url = $item['url'] ?? null;
                        if (! is_string($url) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                            $url = null;
                        }
                        foreach ($slot['mentions'] ?? [] as $mention) {
                            if (! ($mention['is_brand'] ?? false) && is_string($mention['name'] ?? null)) {
                                $competitors[] = $mention['name'];
                            }
                        }
                        if (is_string($slot['provider'] ?? null) && $slot['provider'] !== '') {
                            $models[] = $slot['provider'];
                        }
                        $examples[] = ['url' => $url, 'provider' => $slot['provider'] ?? '', 'question' => data_get($questions->get($slot['qid'] ?? ''), 'text', '')];
                    }
                }

                $action = $actions->get("Проверить присутствие бренда на площадке {$source['domain']}.");

                return ['domain' => $source['domain'], 'answers' => $source['answers'], 'citations' => $citations,
                    'type' => ReportPreparation::SOURCE_TYPES[$source['type'] ?? 'other'] ?? ReportPreparation::SOURCE_TYPES['other'],
                    'profile_url' => $profiles[$source['domain']] ?? null, 'presence' => $presence[$source['domain']] ?? 'unknown', 'competitors' => array_values(array_unique($competitors)), 'models' => array_values(array_unique($models)), 'examples' => array_slice($examples, 0, 20),
                    'action_id' => $action?->id, 'action_status' => $action ? ($action->completed_at ? 'completed' : $action->status) : null];
            })->values()->all();

            return ['sources' => $sources, 'available' => $snapshot !== null, 'canEdit' => Gate::forUser($user)->allows('vision.actions.write')];
        });

        return Inertia::render('Vision/Sources/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    /** Only a confirmed decision attached to verified evidence for this snapshot is projected. */
    private function confirmedProfiles(TenantContext $tenant, array $snapshot): array
    {
        if (! is_string($snapshot['measurement_id'] ?? null)) {
            return [];
        }
        $slots = collect(data_get($snapshot, 'metrics.slots', []))->filter(fn ($slot) => is_array($slot) && ($slot['valid'] ?? false) && is_string($slot['id'] ?? null))->keyBy('id');
        $domains = array_column(data_get($snapshot, 'metrics.sources', []), 'domain');
        $rows = DB::table('vision_clarification_decisions as d')
            ->join('snapshot_confirmations as c', function ($join): void {
                $join->on('c.id', '=', 'd.snapshot_confirmation_id')->on('c.organization_id', '=', 'd.organization_id')->on('c.brand_id', '=', 'd.brand_id');
            })->join('answer_facts as f', function ($join): void {
                $join->on('f.id', '=', 'd.answer_fact_id')->on('f.snapshot_confirmation_id', '=', 'c.id')->on('f.organization_id', '=', 'c.organization_id')->on('f.brand_id', '=', 'c.brand_id');
            })->where('d.organization_id', $tenant->organizationId)->where('d.brand_id', $tenant->brandId)
            ->where('c.measurement_id', $snapshot['measurement_id'])->where('d.kind', 'presence')->where('d.decision', 'yes')
            ->whereNotNull('d.profile_url')->orderByDesc('d.updated_at')->get(['d.item_key', 'd.profile_url', 'f.id as fact_id', 'f.measurement_answer_id', 'f.fact']);
        $profiles = [];
        foreach ($rows as $row) {
            $slot = $slots->get($row->measurement_answer_id);
            $fact = is_string($row->fact) ? json_decode($row->fact, true) : null;
            if (! is_array($fact) || ! is_array($slot['fact'] ?? null) || ! hash_equals(CanonicalJson::hash($slot['fact']), CanonicalJson::hash($fact))) {
                continue;
            }
            $parts = parse_url($row->profile_url);
            if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
                continue;
            }
            $host = strtolower($parts['host'] ?? '');
            foreach (is_array($fact['presence_candidates'] ?? null) ? $fact['presence_candidates'] : [] as $candidate) {
                $domain = is_string($candidate) ? $candidate : (is_array($candidate) ? ($candidate['domain'] ?? null) : null);
                if (! is_string($domain) || ! in_array($domain, $domains, true) || ! hash_equals(hash('sha256', 'presence:'.$row->fact_id.':'.$domain), $row->item_key)) {
                    continue;
                }
                $expected = strtolower($domain);
                if (($host === $expected || str_ends_with($host, '.'.$expected)) && ! isset($profiles[$domain])) {
                    $profiles[$domain] = $row->profile_url;
                }
            }
        }

        return $profiles;
    }

    public function presence(Request $request): mixed
    {
        $input = $request->validate(['domain' => ['required', 'string', 'max:255'], 'presence' => ['required', 'in:present,absent,unknown']]);
        $this->access($request, 'vision.actions.write', function (User $user, TenantContext $tenant) use ($input): void {
            $snapshot = app(SnapshotView::class)->current($tenant) ?? abort(404);
            if (! in_array($input['domain'], array_column(data_get($snapshot, 'metrics.sources', []), 'domain'), true)) {
                throw ValidationException::withMessages(['domain' => 'Источник не найден в снимке.']);
            }
            $query = DB::table('vision_source_presence')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('domain', $input['domain']);
            $old = $query->first();
            $id = $old?->id ?? (string) Str::ulid();
            DB::table('vision_source_presence')->updateOrInsert(['brand_id' => $tenant->brandId, 'domain' => $input['domain']],
                ['id' => $id, 'organization_id' => $tenant->organizationId, 'presence' => $input['presence'], 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('vision.source.presence.updated', 'vision_source_presence', $id, after: ['presence' => $input['presence']]));
        });

        return back()->with('status', 'Отметка сохранена.');
    }
}
