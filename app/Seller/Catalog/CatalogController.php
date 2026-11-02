<?php

namespace App\Seller\Catalog;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CatalogController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function scope(string $table, TenantContext $tenant): Builder
    {
        return DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
    }

    private function data(TenantContext $tenant): array
    {
        $imports = $this->scope('catalog_imports', $tenant)->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'filename', 'status', 'item_count', 'error', 'created_at']);
        $active = $imports->firstWhere('status', 'completed');
        $items = $active ? $this->scope('catalog_items', $tenant)->where('import_id', $active->id)->orderBy('name')->get(['id', 'sku', 'name', 'category', 'price', 'wholesale_price', 'stock', 'updated_at']) : [];
        $settings = $this->scope('catalog_settings', $tenant)->first(['low_stock', 'price_file_name']);

        return ['imports' => $imports, 'items' => $items, 'settings' => ['low_stock' => $settings?->low_stock ?? '0', 'price_file_name' => $settings?->price_file_name], 'activeImport' => $active?->id];
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, fn (User $user, TenantContext $tenant) => $this->data($tenant));

        return Inertia::render('Seller/Catalog/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function stock(Request $request): mixed
    {
        $data = $this->access($request, fn (User $user, TenantContext $tenant) => $this->data($tenant));

        return Inertia::render('Seller/Catalog/Stock', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function upload(Request $request): mixed
    {
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx']]);
        $error = $this->access($request, function (User $user, TenantContext $tenant) use ($input): ?string {
            $file = $input['file'];
            $id = (string) Str::ulid();
            $filename = mb_substr(basename($file->getClientOriginalName()), 0, 200);
            try {
                $items = app(CatalogImporter::class)->parse($file->getRealPath(), $file->getClientOriginalExtension());
            } catch (ValidationException $e) {
                $error = $e->errors()['file'][0] ?? 'Не удалось прочитать файл.';
                DB::table('catalog_imports')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'filename' => $filename, 'status' => 'failed', 'item_count' => 0, 'error' => $error, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

                return $error;
            }
            DB::table('catalog_imports')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'filename' => $filename, 'status' => 'completed', 'item_count' => count($items), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $item) {
                DB::table('catalog_items')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'import_id' => $id, ...$item, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.catalog.imported', 'catalog_import', $id, after: ['item_count' => count($items)]));

            return null;
        });

        return $error ? back()->withErrors(['file' => $error]) : back()->with('status', 'Каталог загружен.');
    }

    private function settings(TenantContext $tenant): object
    {
        $existing = $this->scope('catalog_settings', $tenant)->first();
        if ($existing) {
            return $existing;
        }
        $id = (string) Str::ulid();
        DB::table('catalog_settings')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'created_at' => now(), 'updated_at' => now()]);

        return $this->scope('catalog_settings', $tenant)->where('id', $id)->first();
    }

    public function stockSettings(Request $request): mixed
    {
        $input = $request->validate(['low_stock' => ['required', 'numeric', 'min:0', 'max:99999999999']]);
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $settings = $this->settings($tenant);
            $this->scope('catalog_settings', $tenant)->where('id', $settings->id)->update(['low_stock' => $input['low_stock'], 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.catalog.stock.updated', 'catalog_settings', $settings->id, before: ['low_stock' => $settings->low_stock], after: ['low_stock' => $input['low_stock']]));
        });

        return back()->with('status', 'Порог сохранён.');
    }

    public function priceFile(Request $request): mixed
    {
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx,pdf']]);
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $settings = $this->settings($tenant);
            $file = $input['file'];
            $path = $file->storeAs('seller/catalog/'.$tenant->organizationId.'/'.$tenant->brandId, (string) Str::ulid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
            $this->scope('catalog_settings', $tenant)->where('id', $settings->id)->update(['price_file_path' => $path, 'price_file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.catalog.price_file.updated', 'catalog_settings', $settings->id));
        });

        return back()->with('status', 'Прайс-файл сохранён.');
    }
}
