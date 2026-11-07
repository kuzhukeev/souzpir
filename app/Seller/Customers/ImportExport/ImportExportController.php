<?php

namespace App\Seller\Customers\ImportExport;

use App\Models\User;
use App\Seller\Followups\FollowupConsent;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Data\CustomerSpreadsheet;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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
            $dismissed = $this->scope('seller_distinct_contact_pairs', $tenant)->get(['first_contact_id', 'second_contact_id'])
                ->mapWithKeys(fn ($pair) => [$pair->first_contact_id.':'.$pair->second_contact_id => true])->all();
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
                    $ids = [$a['id'], $b['id']];
                    sort($ids);
                    if (($samePhone || $sameEmail || $sameNameCity) && ! isset($dismissed[implode(':', $ids)])) {
                        $pairs[] = ['first' => $a, 'second' => $b, 'reason' => $samePhone ? 'Телефон' : ($sameEmail ? 'Почта' : 'Имя и город')];
                    }
                }
            }

            return ['pairs' => $pairs, 'contacts' => $rows];
        });

        return Inertia::render('Seller/Customers/ImportExport/Duplicates', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function distinct(Request $request): mixed
    {
        $input = $request->validate(['first' => ['required', 'ulid', 'different:second'], 'second' => ['required', 'ulid']]);
        $this->access($request, 'seller.customers.write', function (User $user, TenantContext $tenant) use ($input): void {
            $ids = [$input['first'], $input['second']];
            sort($ids);
            abort_unless($this->scope('contacts', $tenant)->whereNull('merged_into_id')->whereIn('id', $ids)->count() === 2, 404);
            DB::transaction(function () use ($tenant, $ids): void {
                $existing = $this->scope('seller_distinct_contact_pairs', $tenant)->where('first_contact_id', $ids[0])->where('second_contact_id', $ids[1])->exists();
                if (! $existing) {
                    $id = (string) Str::ulid();
                    DB::table('seller_distinct_contact_pairs')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                        'first_contact_id' => $ids[0], 'second_contact_id' => $ids[1], 'created_at' => now(), 'updated_at' => now()]);
                    app(AuditWriter::class)->record($tenant, new AuditEntry('seller.customer.distinct', 'contact_pair', $id));
                }
            });
        });

        return back()->with('status', 'Пара отмечена как разные клиенты.');
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

    public function export(Request $request): mixed
    {
        $input = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'stage' => ['nullable', 'string', 'max:32'], 'format' => ['required', 'in:csv,xlsx']]);

        return $this->access($request, 'seller.settings.write', function (User $user, TenantContext $tenant) use ($input) {
            abort_unless(DB::table('memberships')->where('id', $tenant->membershipId)->where('role', 'owner')->exists(), 403);
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $input['from'], 'Europe/Moscow')->utc();
            $until = CarbonImmutable::createFromFormat('!Y-m-d', $input['to'], 'Europe/Moscow')->addDay()->utc();
            if ($from->diffInDays($until) > 365) {
                throw ValidationException::withMessages(['to' => 'Период не может превышать 365 дней.']);
            }
            $contacts = $this->scope('contacts', $tenant)->whereNull('merged_into_id')->where('created_at', '>=', $from)->where('created_at', '<', $until)->orderBy('id')->get(['id', 'name', 'phone', 'email', 'city', 'created_at']);
            $base = tempnam(storage_path('app'), 'customer-export-');
            if ($base === false) {
                abort(503);
            }
            $csv = $base.'.csv';
            $xlsx = $base.'.xlsx';
            try {
                $file = fopen($base, 'wb');
                foreach ($contacts as $contact) {
                    if (! empty($input['stage'])) {
                        $stage = $this->scope('conversations', $tenant)->where('contact_id', $contact->id)->orderByDesc('updated_at')->orderByDesc('id')->value('stage');
                        if ($stage !== $input['stage']) {
                            continue;
                        }
                    }
                    fwrite($file, json_encode(['type' => 'contact', 'data' => (array) $contact], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                }
                fclose($file);
                CustomerSpreadsheet::build($base, $csv, $xlsx);
                $output = $input['format'] === 'csv' ? $csv : $xlsx;
                @unlink($base);
                @unlink($input['format'] === 'csv' ? $xlsx : $csv);
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.customers.exported', 'brand', $tenant->brandId, after: ['format' => $input['format'], 'stage' => $input['stage'] ?? null]));

                return response()->download($output, 'customers.'.$input['format'])->deleteFileAfterSend(true);
            } catch (\Throwable $error) {
                @unlink($base);
                @unlink($csv);
                @unlink($xlsx);
                throw $error;
            }
        });
    }

    public function template(Request $request): mixed
    {
        $this->access($request, 'seller.settings.write', fn () => null);

        return response("\xEF\xBB\xBFИмя;Телефон;Почта;Город\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customers-template.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
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
            'mapping.phone' => ['nullable', 'integer', 'min:0'], 'mapping.email' => ['nullable', 'integer', 'min:0'], 'mapping.city' => ['nullable', 'integer', 'min:0'],
            'mapping.consent' => ['nullable', 'integer', 'min:0']]);
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
                $validation = Validator::make($values, ['name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:254'], 'city' => ['nullable', 'string', 'max:120'], 'consent' => ['nullable', 'string', 'max:50']]);
                if ($validation->fails()) {
                    throw ValidationException::withMessages(['mapping' => 'Проверьте данные в строке '.($index + 2).'.']);
                }
                $contacts[] = $values;
            }
            DB::transaction(function () use ($contacts, $tenant, $record, $user): void {
                foreach ($contacts as $values) {
                    $id = (string) Str::ulid();
                    DB::table('contacts')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                        'name' => $values['name'] ?? null, 'phone' => $values['phone'] ?? null, 'email' => $values['email'] ?? null, 'city' => $values['city'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                    if (preg_match('/^(да|yes|1|true)$/ui', trim((string) ($values['consent'] ?? '')))) {
                        $conversation = (string) Str::ulid();
                        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                            'contact_id' => $id, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                        app(FollowupConsent::class)->record($tenant, $conversation, 'granted', 'import', 'Согласие указано в файле импорта CRM.', actor: $user->id);
                    }
                }
                $this->scope('seller_contact_imports', $tenant)->where('id', $record->id)->update(['status' => 'imported', 'updated_at' => now()]);
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.customer.imported', 'seller_contact_import', $record->id, after: ['count' => count($contacts)]));
            });
        });

        return back()->with('status', 'Клиенты импортированы.');
    }
}
