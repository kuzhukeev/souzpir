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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        [$editor, $documents] = $this->access($request, function (TenantContext $tenant): array {
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id');

            $editor = $id ? app(KnowledgeRepository::class)->draftEditor($tenant, $id) : null;
            $documents = DB::table('seller_knowledge_import_documents')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->orderByDesc('created_at')->get(['id', 'name', 'type', 'pages', 'status', 'fragments_count', 'created_at', 'source_path'])->map(function ($document) {
                $document->has_source = is_string($document->source_path) && Storage::disk('local')->exists($document->source_path);
                unset($document->source_path);

                return $document;
            });

            return [$editor, $documents];
        });

        return Inertia::render('Seller/Knowledge/Imports/Index', ['editor' => $editor, 'documents' => $documents, 'status' => session('status')]);
    }

    public function preview(Request $request): mixed
    {
        return $this->access($request, function (TenantContext $tenant) use ($request) {
            $input = $request->validate(['file' => ['required', 'file', 'max:10240'], 'sheet' => ['nullable', 'integer', 'min:0', 'max:99'], 'document_id' => ['nullable', 'ulid']]);
            $file = $input['file'];
            $extension = strtolower($file->getClientOriginalExtension());
            [$lines, $sheets, $pages] = $this->readSource($file->getRealPath(), $extension, (int) ($input['sheet'] ?? 0));
            $name = mb_substr($file->getClientOriginalName(), 0, 255);
            $documentId = $input['document_id'] ?? null;
            if ($documentId !== null) {
                abort_unless(DB::table('seller_knowledge_import_documents')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->where('id', $documentId)->where('name', $name)
                    ->where('type', $extension)->where('status', 'previewed')->exists(), 404);
            } else {
                $documentId = (string) Str::ulid();
                DB::table('seller_knowledge_import_documents')->insert(['id' => $documentId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'name' => $name, 'type' => $extension, 'pages' => $pages, 'status' => 'previewed', 'created_at' => now(), 'updated_at' => now()]);
            }

            $directory = 'knowledge-imports/'.$tenant->organizationId.'/'.$tenant->brandId.'/'.$documentId;
            $path = Storage::disk('local')->putFileAs($directory, $file, (string) Str::ulid().'.'.$extension);
            if (! is_string($path)) {
                throw ValidationException::withMessages(['file' => 'Не удалось сохранить исходный файл.']);
            }
            DB::table('seller_knowledge_import_documents')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $documentId)->update(['fragments_count' => count($lines), 'source_path' => $path, 'updated_at' => now()]);

            return response()->json(['proposals' => $lines, 'document_id' => $documentId, 'sheets' => $sheets, 'document' => ['id' => $documentId, 'name' => $name, 'type' => $extension, 'pages' => $pages, 'status' => 'previewed', 'fragments_count' => count($lines), 'has_source' => true, 'created_at' => now()->toIso8601String()]])->header('Cache-Control', 'private, no-store');
        });
    }

    private function readSource(string $path, string $extension, int $sheet): array
    {
        $pages = null;
        $sheets = [];
        if (in_array($extension, ['pdf', 'docx'], true)) {
            $document = app(DocumentReader::class)->read($path, $extension);
            $pages = $document['pages'];
            $lines = preg_split('/\R/u', $document['text']);
        } elseif ($extension === 'txt') {
            $text = file_get_contents($path);
            if (! mb_check_encoding($text, 'UTF-8')) {
                throw ValidationException::withMessages(['file' => 'TXT должен быть в кодировке UTF-8.']);
            }
            $lines = preg_split('/\R/u', $text);
        } elseif (in_array($extension, ['csv', 'xlsx'], true)) {
            $data = app(CatalogReader::class)->read($path, $extension, $sheet);
            $sheets = $extension === 'xlsx' ? $data['sheets'] : [];
            $lines = array_map(fn (array $row) => implode(' · ', array_filter(array_map(fn ($cell) => trim((string) $cell), $row), fn ($cell) => $cell !== '')), $data['rows']);
        } else {
            throw ValidationException::withMessages(['file' => 'Поддерживаются TXT, CSV, XLSX, PDF и DOCX.']);
        }
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
        if (count($lines) > 100 || collect($lines)->contains(fn ($line) => mb_strlen($line) > 500)) {
            throw ValidationException::withMessages(['file' => 'Не более 100 строк по 500 символов.']);
        }

        if ($lines === [] && $extension !== 'xlsx') {
            throw ValidationException::withMessages(['file' => 'В документе не найден текст.']);
        }

        return [$lines, $sheets, $pages];
    }

    public function previewSaved(Request $request, string $document): mixed
    {
        return $this->access($request, function (TenantContext $tenant) use ($request, $document) {
            $input = $request->validate(['sheet' => ['nullable', 'integer', 'min:0', 'max:99']]);
            $row = DB::table('seller_knowledge_import_documents')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('id', $document)->first();
            abort_unless($row && is_string($row->source_path) && Storage::disk('local')->exists($row->source_path), 404);
            [$lines, $sheets, $pages] = $this->readSource(Storage::disk('local')->path($row->source_path), $row->type, (int) ($input['sheet'] ?? 0));

            return response()->json(['proposals' => $lines, 'document_id' => $row->id, 'sheets' => $sheets,
                'document' => ['id' => $row->id, 'name' => $row->name, 'type' => $row->type, 'pages' => $pages,
                    'status' => $row->status, 'fragments_count' => $row->fragments_count, 'has_source' => true,
                    'created_at' => $row->created_at]])->header('Cache-Control', 'private, no-store');
        });
    }

    public function download(Request $request, string $document): mixed
    {
        return $this->access($request, function (TenantContext $tenant) use ($document) {
            $row = DB::table('seller_knowledge_import_documents')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('id', $document)->first(['name', 'source_path']);
            abort_unless($row && is_string($row->source_path) && Storage::disk('local')->exists($row->source_path), 404);

            return Storage::disk('local')->download($row->source_path, $row->name, ['Cache-Control' => 'private, no-store']);
        });
    }

    public function save(Request $request): mixed
    {
        return $this->access($request, function (TenantContext $tenant) use ($request) {
            $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'group' => ['required', 'string', 'max:64'], 'facts' => ['required', 'array', 'min:1', 'max:100'], 'facts.*' => ['required', 'string', 'max:500'], 'document_id' => ['nullable', 'string', 'size:26']]);
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
            if (! empty($input['document_id'])) {
                abort_unless(DB::table('seller_knowledge_import_documents')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $input['document_id'])->exists(), 404);
            }
            DB::transaction(function () use ($tenant, $id, $input) {
                $repo = app(KnowledgeRepository::class);
                $version = (int) $input['version'];
                foreach ($input['facts'] as $fact) {
                    $result = $repo->addFact($tenant, $id, $version, $input['group'], trim($fact), 'import');
                    $version = $result['version'];
                }
                if (! empty($input['document_id'])) {
                    DB::table('seller_knowledge_import_documents')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $input['document_id'])->update(['status' => 'imported', 'updated_at' => now()]);
                }
            });

            return back()->with('status', 'Выбранные факты добавлены в черновик.');
        });
    }
}
