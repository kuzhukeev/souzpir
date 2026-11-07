<?php

namespace App\Seller\Catalog;

use App\Models\User;
use App\Seller\Channels\Email\PriceMailSender;
use App\Seller\Engine\ToolRunner;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
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
        $itemsError = false;
        try {
            $items = $active ? $this->scope('catalog_items', $tenant)->where('import_id', $active->id)->orderBy('name')->get(['id', 'sku', 'name', 'category', 'price', 'wholesale_price', 'stock', 'updated_at']) : [];
        } catch (QueryException) {
            $items = [];
            $itemsError = true;
        }
        $settings = $this->scope('catalog_settings', $tenant)->first(['low_stock', 'price_file_name']);
        $sellerSettings = $this->scope('brand_seller_settings', $tenant)->first(['catalog_prices_enabled', 'tools']);
        $tools = $sellerSettings ? json_decode($sellerSettings->tools, true) : [];
        $priceOptions = ['prices_enabled' => (bool) ($sellerSettings?->catalog_prices_enabled ?? false), 'discounts_enabled' => (bool) ($tools['catalog_discounts_enabled'] ?? false)];

        return ['itemsError' => $itemsError, 'priceOptions' => $priceOptions, 'imports' => $imports, 'items' => $items, 'settings' => ['low_stock' => $settings?->low_stock ?? '0', 'price_file_name' => $settings?->price_file_name], 'activeImport' => $active?->id, 'yml' => ($feed = app(YmlFeedStore::class)->read($tenant->organizationId, $tenant->brandId)) ? ['url' => $feed['url'], 'interval_hours' => $feed['interval_hours'], 'last_run_at' => $feed['last_run_at']] : null];
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, fn (User $user, TenantContext $tenant) => $this->data($tenant));
        $page = $request->query('view') === 'settings' ? 'Seller/Catalog/Settings' : 'Seller/Catalog/Index';

        return Inertia::render($page, [...$data, 'status' => $request->session()->get('status')]);
    }

    public function stock(Request $request): mixed
    {
        $data = $this->access($request, function (User $user, TenantContext $tenant): array {
            $data = $this->data($tenant);
            $sample = collect($data['items'])->first(fn ($item) => mb_strlen(trim($item->sku ?? '')) >= 2);
            $data['stockExample'] = $sample ? app(ToolRunner::class)->run('check_stock', ['query' => $sample->sku], $tenant) : null;
            if (($data['stockExample']['status'] ?? null) === 'completed') {
                $data['stockExample']['items'] = array_values(array_filter($data['stockExample']['items'], fn ($item) => $item['sku'] === $sample->sku));
            }

            return $data;
        });

        return Inertia::render('Seller/Catalog/Stock', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function upload(Request $request): mixed
    {
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx'], 'preview' => ['sometimes', 'boolean'], 'sheet' => ['sometimes', 'integer', 'min:0'], 'columns' => ['sometimes', 'array']]);
        if ($input['preview'] ?? false) {
            return $this->access($request, function () use ($input) {
                $data = app(CatalogReader::class)->read($input['file']->getRealPath(), $input['file']->getClientOriginalExtension(), (int) ($input['sheet'] ?? 0));

                return response()->json(['sheets' => $data['sheets'], 'header' => $data['rows'][0] ?? [], 'suggested' => app(CatalogReader::class)->suggest($data['rows'][0] ?? []), 'sample' => array_slice($data['rows'], 1, 3)]);
            });
        }
        $error = $this->access($request, function (User $user, TenantContext $tenant) use ($input): ?string {
            $file = $input['file'];
            $id = (string) Str::ulid();
            $filename = mb_substr(basename($file->getClientOriginalName()), 0, 200);
            try {
                $items = app(CatalogImporter::class)->parse($file->getRealPath(), $file->getClientOriginalExtension(), (int) ($input['sheet'] ?? 0), $input['columns'] ?? null);
            } catch (ValidationException $e) {
                $error = collect($e->errors())->flatten()->first() ?? 'Не удалось прочитать файл.';
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
        if ($request->input('catalog_options') === true) {
            $input = $request->validate(['prices_enabled' => ['required', 'boolean'], 'discounts_enabled' => ['required', 'boolean']]);
            $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
                $scope = $this->scope('brand_seller_settings', $tenant);
                $settings = $scope->first();
                $tools = $settings ? json_decode($settings->tools, true) : [];
                $tools = is_array($tools) ? $tools : [];
                $tools['catalog_discounts_enabled'] = (bool) $input['discounts_enabled'];
                $values = ['catalog_prices_enabled' => (bool) $input['prices_enabled'], 'tools' => json_encode($tools, JSON_THROW_ON_ERROR), 'updated_at' => now()];
                $id = $settings?->id ?? (string) Str::ulid();
                if ($settings) {
                    $scope->update($values);
                } else {
                    DB::table('brand_seller_settings')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'followup' => '{"first_hours":20,"second_hours":null,"max_auto":2,"window":null,"texts":[]}', 'off_topic' => '{"streak":3,"action":"spam"}', 'negative' => '{"streak":2}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}', 'created_at' => now(), ...$values]);
                }
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.catalog.price_options.updated', 'brand_seller_settings', $id));
            });

            return back()->with('status', 'Настройки цен сохранены.');
        }
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
        $action = $request->input('action');
        if ($action === 'delete_item') {
            $input = $request->validate(['item_id' => ['required', 'string']]);
            $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
                $deleted = $this->scope('catalog_items', $tenant)->where('id', $input['item_id'])->delete();
                abort_unless($deleted > 0, 404);
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.catalog.item.deleted', 'catalog_item', $input['item_id']));
            });

            return back()->with('status', 'Товар удалён.');
        }
        if ($action === 'send_test') {
            $sent = $this->access($request, fn (User $user, TenantContext $tenant) => app(PriceMailSender::class)->send($tenant, $user->email));

            return $sent
                ? back()->with('status', "Тестовое письмо отправлено на {$request->user()->email}.")
                : back()->withErrors(['send' => 'Не удалось отправить: настройте почту, файл или ссылку прайса.']);
        }
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
