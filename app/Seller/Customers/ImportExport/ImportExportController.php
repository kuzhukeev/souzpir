<?php

namespace App\Seller\Customers\ImportExport;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ImportExportController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function scope(string $table, TenantContext $tenant): Builder
    {
        return DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
    }

    public function duplicates(Request $request): mixed
    {
        $data = $this->access($request, 'seller.customers.write', function (User $user, TenantContext $tenant): array {
            $contacts = $this->scope('contacts', $tenant)->whereNull('merged_into_id')->orderBy('name')->get(['id', 'name', 'phone', 'email', 'city']);
            $rows = $contacts->map(function ($contact) use ($tenant): array {
                $state = $this->scope('conversations', $tenant)->where('contact_id', $contact->id)->orderByDesc('updated_at')->value('state');
                $state = $state ? json_decode($state, true) : [];

                return ['id' => $contact->id, 'name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email, 'city' => $contact->city ?: data_get($state, 'slots.city')];
            })->all();
            $pairs = [];
            for ($i = 0; $i < count($rows) && count($pairs) < 100; $i++) {
                for ($j = $i + 1; $j < count($rows) && count($pairs) < 100; $j++) {
                    $a = $rows[$i];
                    $b = $rows[$j];
                    $phoneA = preg_replace('/\D+/', '', $a['phone'] ?? '');
                    $phoneB = preg_replace('/\D+/', '', $b['phone'] ?? '');
                    $samePhone = $phoneA !== '' && $phoneA === $phoneB;
                    $sameEmail = ! empty($a['email']) && mb_strtolower($a['email']) === mb_strtolower($b['email'] ?? '');
                    $sameNameCity = ! empty($a['name']) && ! empty($a['city']) && mb_strtolower(trim($a['name'])) === mb_strtolower(trim($b['name'] ?? '')) && mb_strtolower(trim($a['city'])) === mb_strtolower(trim($b['city'] ?? ''));
                    if ($samePhone || $sameEmail || $sameNameCity) {
                        $pairs[] = ['first' => $a, 'second' => $b, 'reason' => $samePhone ? 'Телефон' : ($sameEmail ? 'Почта' : 'Имя и город')];
                    }
                }
            }

            return ['pairs' => $pairs, 'contacts' => $rows];
        });

        return Inertia::render('Seller/Customers/ImportExport/Duplicates', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function merge(Request $request): mixed
    {
        $input = $request->validate(['source' => ['required', 'ulid', 'different:target'], 'target' => ['required', 'ulid']]);
        $this->access($request, 'seller.customers.write', function (User $user, TenantContext $tenant) use ($input): void {
            DB::transaction(function () use ($tenant, $input): void {
                $source = $this->scope('contacts', $tenant)->where('id', $input['source'])->whereNull('merged_into_id')->first() ?? abort(404);
                $target = $this->scope('contacts', $tenant)->where('id', $input['target'])->whereNull('merged_into_id')->first() ?? abort(404);
                $this->scope('conversations', $tenant)->where('contact_id', $source->id)->update(['contact_id' => $target->id, 'updated_at' => now()]);
                $this->scope('seller_customer_notes', $tenant)->where('contact_id', $source->id)->update(['contact_id' => $target->id, 'updated_at' => now()]);
                $this->scope('contacts', $tenant)->where('id', $target->id)->update(['name' => $target->name ?: $source->name, 'phone' => $target->phone ?: $source->phone, 'email' => $target->email ?: $source->email, 'city' => $target->city ?: $source->city, 'updated_at' => now()]);
                $this->scope('contacts', $tenant)->where('id', $source->id)->update(['merged_into_id' => $target->id, 'updated_at' => now()]);
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.customer.merged', 'contact', $target->id, after: ['source_id' => $source->id]));
            });
        });

        return back()->with('status', 'Контакты объединены.');
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'seller.settings.write', function (User $user, TenantContext $tenant): array {
            $record = $this->scope('seller_contact_imports', $tenant)->where('user_id', $user->id)->where('status', 'preview')->orderByDesc('created_at')->first();
            $preview = null;
            if ($record) {
                $decoded = json_decode(Crypt::decryptString($record->data_encrypted), true, 512, JSON_THROW_ON_ERROR);
                $preview = ['id' => $record->id, 'filename' => $record->filename, 'headers' => $decoded['headers'], 'rows' => array_slice($decoded['rows'], 0, 10), 'count' => count($decoded['rows'])];
            }

            return ['preview' => $preview];
        });

        return Inertia::render('Seller/Customers/ImportExport/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function preview(Request $request): mixed
    {
        $input = $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv']]);
        $this->access($request, 'seller.settings.write', function (User $user, TenantContext $tenant) use ($input): void {
            $parsed = app(CsvContacts::class)->read($input['file']->getRealPath());
            $this->scope('seller_contact_imports', $tenant)->where('user_id', $user->id)->where('status', 'preview')->update(['status' => 'replaced', 'updated_at' => now()]);
            DB::table('seller_contact_imports')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'user_id' => $user->id, 'filename' => mb_substr(basename($input['file']->getClientOriginalName()), 0, 200),
                'data_encrypted' => Crypt::encryptString(json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'status' => 'preview', 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', 'Файл прочитан. Проверьте соответствие колонок.');
    }

    public function import(Request $request): mixed
    {
        $input = $request->validate(['id' => ['required', 'ulid'], 'mapping' => ['required', 'array'], 'mapping.name' => ['nullable', 'integer', 'min:0'],
            'mapping.phone' => ['nullable', 'integer', 'min:0'], 'mapping.email' => ['nullable', 'integer', 'min:0'], 'mapping.city' => ['nullable', 'integer', 'min:0']]);
        $this->access($request, 'seller.settings.write', function (User $user, TenantContext $tenant) use ($input): void {
            $record = $this->scope('seller_contact_imports', $tenant)->where('id', $input['id'])->where('user_id', $user->id)->where('status', 'preview')->first() ?? abort(404);
            $data = json_decode(Crypt::decryptString($record->data_encrypted), true, 512, JSON_THROW_ON_ERROR);
            $mapping = array_filter($input['mapping'], fn ($v) => $v !== null);
            if (! array_intersect(['name', 'phone', 'email'], array_keys($mapping)) || count(array_unique($mapping)) !== count($mapping)) {
                throw ValidationException::withMessages(['mapping' => 'Выберите разные колонки, включая имя, телефон или почту.']);
            }
            foreach ($mapping as $column) {
                if ((int) $column >= count($data['headers'])) {
                    throw ValidationException::withMessages(['mapping' => 'Некорректная колонка.']);
                }
            }
            $contacts = [];
            foreach ($data['rows'] as $index => $row) {
                $values = [];
                foreach ($mapping as $field => $column) {
                    $values[$field] = $row[$column] ?? '';
                }
                if (empty($values['name']) && empty($values['phone']) && empty($values['email'])) {
                    throw ValidationException::withMessages(['mapping' => 'Нет контакта в строке '.($index + 2).'.']);
                }
                $validation = Validator::make($values, ['name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:254'], 'city' => ['nullable', 'string', 'max:120']]);
                if ($validation->fails()) {
                    throw ValidationException::withMessages(['mapping' => 'Проверьте данные в строке '.($index + 2).'.']);
                }
                $contacts[] = $values;
            }
            DB::transaction(function () use ($contacts, $tenant, $record): void {
                foreach ($contacts as $values) {
                    $id = (string) Str::ulid();
                    DB::table('contacts')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                        'name' => $values['name'] ?? null, 'phone' => $values['phone'] ?? null, 'email' => $values['email'] ?? null, 'city' => $values['city'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->scope('seller_contact_imports', $tenant)->where('id', $record->id)->update(['status' => 'imported', 'updated_at' => now()]);
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.customer.imported', 'seller_contact_import', $record->id, after: ['count' => count($contacts)]));
            });
        });

        return back()->with('status', 'Клиенты импортированы.');
    }
}
