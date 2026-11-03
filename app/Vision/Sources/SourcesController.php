<?php

namespace App\Vision\Sources;

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
            $sources = collect(data_get($snapshot, 'metrics.sources', []))->map(function ($source) use ($presence, $snapshot, $questions): array {
                $examples = [];
                $competitors = [];
                foreach (data_get($snapshot, 'metrics.slots', []) as $slot) {
                    if (! ($slot['valid'] ?? false)) {
                        continue;
                    }
                    foreach ($slot['sources'] ?? [] as $item) {
                        if (($item['domain'] ?? null) !== $source['domain']) {
                            continue;
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
                        $examples[] = ['url' => $url, 'provider' => $slot['provider'] ?? '', 'question' => data_get($questions->get($slot['qid'] ?? ''), 'text', '')];
                    }
                }

                return ['domain' => $source['domain'], 'answers' => $source['answers'],
                    'type' => ReportPreparation::SOURCE_TYPES[$source['type'] ?? 'other'] ?? ReportPreparation::SOURCE_TYPES['other'],
                    'presence' => $presence[$source['domain']] ?? 'unknown', 'competitors' => array_values(array_unique($competitors)), 'examples' => array_slice($examples, 0, 20)];
            })->values()->all();

            return ['sources' => $sources, 'available' => $snapshot !== null, 'canEdit' => Gate::forUser($user)->allows('vision.actions.write')];
        });

        return Inertia::render('Vision/Sources/Index', [...$data, 'status' => $request->session()->get('status')]);
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
