<?php

namespace App\Vision\Competitors;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CompetitorsController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(ModuleEntitlements::class)->enabled($tenant, 'vision'), 404);

            return $callback($user, $tenant);
        });
    }

    private function scope(string $table, TenantContext $tenant): Builder
    {
        return DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'vision.report.read', function (User $user, TenantContext $tenant): array {
            $snapshot = app(SnapshotView::class)->current($tenant);
            if (! $snapshot) {
                return ['entities' => [], 'available' => false, 'canEdit' => Gate::forUser($user)->allows('vision.actions.write')];
            }
            $aliases = $this->scope('vision_competitor_aliases', $tenant)->pluck('canonical', 'alias')->all();
            $tracked = $this->scope('vision_competitor_tracking', $tenant)->pluck('name')->all();
            $metrics = app(SnapshotView::class)->metrics($snapshot, $aliases);
            $entities = collect($metrics['entities'] ?? [])->map(function ($entity) use ($metrics, $tracked, $snapshot): array {
                $matched = collect($metrics['slots'] ?? [])->filter(function ($slot) use ($entity): bool {
                    if (! ($slot['valid'] ?? false)) {
                        return false;
                    }
                    foreach ($slot['mentions'] ?? [] as $mention) {
                        if (($mention['name'] ?? null) === $entity['name']) {
                            return true;
                        }
                    }

                    return false;
                });
                $questions = collect($snapshot['questions'] ?? [])->keyBy('id');

                return ['name' => $entity['name'], 'answers' => $entity['answers'], 'share' => $entity['share_of_voice'] ?? null,
                    'is_brand' => $entity['is_brand'], 'tracked' => in_array($entity['name'], $tracked, true),
                    'models' => $matched->pluck('provider')->unique()->values()->all(),
                    'intents' => $matched->pluck('intent')->filter()->unique()->values()->all(),
                    'questions' => $matched->pluck('qid')->unique()->map(fn ($id) => data_get($questions->get($id), 'text'))->filter()->values()->all(),
                    'sources' => $matched->flatMap(fn ($slot) => array_column($slot['sources'] ?? [], 'domain'))->filter()->unique()->values()->all()];
            })->values()->all();

            return ['entities' => $entities, 'available' => true, 'canEdit' => Gate::forUser($user)->allows('vision.actions.write')];
        });

        return Inertia::render('Vision/Competitors/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    private function validNames(TenantContext $tenant): array
    {
        $snapshot = app(SnapshotView::class)->current($tenant) ?? abort(404);
        $aliases = $this->scope('vision_competitor_aliases', $tenant)->pluck('canonical', 'alias')->all();

        return array_column(app(SnapshotView::class)->metrics($snapshot, $aliases)['entities'] ?? [], 'name');
    }

    public function merge(Request $request): mixed
    {
        $input = $request->validate(['source' => ['required', 'string', 'max:255', 'different:target'], 'target' => ['required', 'string', 'max:255']]);
        $this->access($request, 'vision.actions.write', function (User $user, TenantContext $tenant) use ($input): void {
            $names = $this->validNames($tenant);
            if (! in_array($input['source'], $names, true) || ! in_array($input['target'], $names, true)) {
                throw ValidationException::withMessages(['source' => 'Выберите названные компании текущего снимка.']);
            }
            $this->scope('vision_competitor_aliases', $tenant)->where('canonical', $input['source'])->update(['canonical' => $input['target'], 'updated_at' => now()]);
            $existing = $this->scope('vision_competitor_aliases', $tenant)->where('alias', $input['source'])->first();
            DB::table('vision_competitor_aliases')->updateOrInsert(['brand_id' => $tenant->brandId, 'alias' => $input['source']],
                ['id' => $existing?->id ?? (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'canonical' => $input['target'], 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
            $oldTracking = $this->scope('vision_competitor_tracking', $tenant)->where('name', $input['source'])->first();
            if ($oldTracking) {
                $targetTracking = $this->scope('vision_competitor_tracking', $tenant)->where('name', $input['target'])->first();
                if ($targetTracking) {
                    $this->scope('vision_competitor_tracking', $tenant)->where('id', $oldTracking->id)->delete();
                } else {
                    $this->scope('vision_competitor_tracking', $tenant)->where('id', $oldTracking->id)->update(['name' => $input['target'], 'updated_at' => now()]);
                }
            }
            app(AuditWriter::class)->record($tenant, new AuditEntry('vision.competitor.merged', 'vision_competitor_alias', $existing?->id ?? DB::table('vision_competitor_aliases')->where('brand_id', $tenant->brandId)->where('alias', $input['source'])->value('id')));
        });

        return back()->with('status', 'Названия объединены в этом разделе.');
    }

    public function track(Request $request): mixed
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:255'], 'tracked' => ['required', 'boolean']]);
        $this->access($request, 'vision.actions.write', function (User $user, TenantContext $tenant) use ($input): void {
            if (! in_array($input['name'], $this->validNames($tenant), true)) {
                throw ValidationException::withMessages(['name' => 'Компания не найдена в снимке.']);
            }
            $query = $this->scope('vision_competitor_tracking', $tenant)->where('name', $input['name']);
            $row = $query->first();
            if ($input['tracked'] && ! $row) {
                DB::table('vision_competitor_tracking')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'name' => $input['name'], 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! $input['tracked'] && $row) {
                $query->delete();
            }
            app(AuditWriter::class)->record($tenant, new AuditEntry('vision.competitor.tracking.updated', 'brand', $tenant->brandId, after: ['tracked' => $input['tracked']]));
        });

        return back();
    }
}
