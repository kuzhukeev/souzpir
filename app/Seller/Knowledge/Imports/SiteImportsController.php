<?php

namespace App\Seller\Knowledge\Imports;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Vision\SiteAudit\Knowledge\SiteDraftStore;
use App\Vision\SiteAudit\Knowledge\SiteKnowledgeImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SiteImportsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    private function lines(array $draft): array
    {
        $lines = preg_split('/\R+/u', $draft['content_text'] ?? '');

        return array_slice(array_values(array_filter(array_map(fn ($line) => mb_substr(trim($line), 0, 500), $lines), fn ($line) => $line !== '')), 0, 100);
    }

    public function preview(Request $request): mixed
    {
        $input = $request->validate(['url' => ['required', 'url', 'max:2048']]);
        $tenant = $this->access($request, fn (TenantContext $tenant) => $tenant);
        $result = app(SiteKnowledgeImporter::class)->import($tenant, $input['url'], 1);
        $this->access($request, fn () => null);
        $source = $result['sources'][0]['url'] ?? null;
        if (! $source) {
            throw ValidationException::withMessages(['url' => 'Не удалось импортировать страницу по правилам источника.']);
        }
        $draft = app(SiteDraftStore::class)->read($tenant, $source) ?? abort(404);

        return response()->json(['url' => $source, 'version_id' => $draft['version_id'], 'proposals' => $this->lines($draft)])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['url' => ['required', 'url', 'max:2048'], 'version_id' => ['required', 'ulid'], 'version' => ['required', 'integer', 'min:1'], 'group' => ['required', 'string', 'max:64'], 'selected' => ['required', 'array', 'min:1', 'max:100'], 'selected.*' => ['required', 'integer', 'min:0', 'max:99', 'distinct']]);

        return $this->access($request, function (TenantContext $tenant) use ($input) {
            $draft = app(SiteDraftStore::class)->read($tenant, $input['url']) ?? abort(404);
            if ($draft['version_id'] !== $input['version_id']) {
                throw ValidationException::withMessages(['url' => 'Источник изменился, загрузите предложения заново.']);
            }
            $lines = $this->lines($draft);
            foreach ($input['selected'] as $index) {
                if (! isset($lines[$index])) {
                    throw ValidationException::withMessages(['selected' => 'Предложение не найдено.']);
                }
            }
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $version = (int) $input['version'];
            foreach ($input['selected'] as $index) {
                $added = $repo->addFact($tenant, $id, $version, $input['group'], $lines[$index], 'url');
                $version = $added['version'];
                app(AuditWriter::class)->record($tenant, new AuditEntry('knowledge.site_fact.imported', 'knowledge_fact', $added['id'], after: ['source_url' => $draft['source_url'], 'source_version_id' => $draft['version_id']]));
            }

            return back()->with('status', 'Выбранные факты сайта добавлены в черновик.');
        });
    }
}
