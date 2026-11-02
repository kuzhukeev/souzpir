<?php

namespace App\Seller\Knowledge\Imports;

use App\Models\User;
use App\Seller\Catalog\CatalogReader;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ImportsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $editor = $this->access($request, function (TenantContext $tenant): ?array {
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id');

            return $id ? app(KnowledgeRepository::class)->draftEditor($tenant, $id) : null;
        });

        return Inertia::render('Seller/Knowledge/Imports/Index', ['editor' => $editor, 'status' => session('status')]);
    }

    public function preview(Request $request): mixed
    {
        return $this->access($request, function () use ($request) {
            $input = $request->validate(['file' => ['required', 'file', 'max:10240']]);
            $file = $input['file'];
            $extension = strtolower($file->getClientOriginalExtension());
            if ($extension === 'txt') {
                $text = file_get_contents($file->getRealPath());
                if (! mb_check_encoding($text, 'UTF-8')) {
                    throw ValidationException::withMessages(['file' => 'TXT должен быть в кодировке UTF-8.']);
                }
                $lines = preg_split('/\R/u', $text);
            } elseif (in_array($extension, ['csv', 'xlsx'], true)) {
                $data = app(CatalogReader::class)->read($file->getRealPath(), $extension);
                $lines = array_map(fn (array $row) => implode(' · ', array_filter(array_map(fn ($cell) => trim((string) $cell), $row), fn ($cell) => $cell !== '')), $data['rows']);
            } else {
                throw ValidationException::withMessages(['file' => 'Поддерживаются TXT, CSV и XLSX.']);
            }
            $lines = array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
            if (count($lines) > 100 || collect($lines)->contains(fn ($line) => mb_strlen($line) > 500)) {
                throw ValidationException::withMessages(['file' => 'Не более 100 строк по 500 символов.']);
            }

            return response()->json(['proposals' => $lines])->header('Cache-Control', 'private, no-store');
        });
    }

    public function save(Request $request): mixed
    {
        return $this->access($request, function (TenantContext $tenant) use ($request) {
            $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'group' => ['required', 'string', 'max:64'], 'facts' => ['required', 'array', 'min:1', 'max:100'], 'facts.*' => ['required', 'string', 'max:500']]);
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
            DB::transaction(function () use ($tenant, $id, $input) {
                $repo = app(KnowledgeRepository::class);
                $version = (int) $input['version'];
                foreach ($input['facts'] as $fact) {
                    $result = $repo->addFact($tenant, $id, $version, $input['group'], trim($fact), 'import');
                    $version = $result['version'];
                }
            });

            return back()->with('status', 'Выбранные факты добавлены в черновик.');
        });
    }
}
