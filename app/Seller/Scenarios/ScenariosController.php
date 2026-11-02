<?php

namespace App\Seller\Scenarios;

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
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ScenariosController
{
    private const EXPECT = ['full_card', 'partial_or_full_contact', 'no_deferred_promise', 'no_argument', 'one_question_per_turn'];

    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    private function query(string $table, TenantContext $tenant): Builder
    {
        return DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (TenantContext $tenant): array {
            $scenarios = $this->query('seller_personas', $tenant)->orderBy('name')->get(['id', 'key', 'name', 'type', 'payload', 'max_turns'])->map(function ($row) {
                $payload = json_decode($row->payload, true) ?: [];

                return ['id' => $row->id, 'name' => $row->name, 'type' => $row->type, 'max_turns' => $row->max_turns, 'behavior' => $payload['behavior'] ?? '',
                    'first_message' => $payload['first_message'] ?? '', 'expect' => $payload['expect'] ?? [], 'editable' => ($payload['custom'] ?? false) === true];
            });
            $runs = $this->query('seller_sim_runs', $tenant)->orderByDesc('created_at')->limit(30)->get(['id', 'persona_id', 'status', 'turn_count', 'turns', 'created_at'])->map(fn ($row) => ['id' => $row->id, 'persona_id' => $row->persona_id, 'status' => $row->status, 'turn_count' => $row->turn_count, 'turns' => json_decode($row->turns, true) ?: [], 'created_at' => $row->created_at]);

            return ['scenarios' => $scenarios, 'runs' => $runs];
        });

        return Inertia::render('Seller/Scenarios/Index', [...$data, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['id' => ['nullable', 'ulid'], 'name' => ['required', 'string', 'max:200'], 'type' => ['required', Rule::in(['newbie', 'experienced', 'for_self', 'prices_only', 'troll', 'edge'])],
            'behavior' => ['required', 'string', 'max:2000'], 'first_message' => ['nullable', 'string', 'max:1000'], 'max_turns' => ['required', 'integer', 'min:1', 'max:30'],
            'expect' => ['present', 'array', 'max:5'], 'expect.*' => ['required', Rule::in(self::EXPECT)]]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $existing = isset($input['id']) ? $this->query('seller_personas', $tenant)->where('id', $input['id'])->first() : null;
            if (isset($input['id']) && ! $existing) {
                abort(404);
            }
            $payload = $existing ? json_decode($existing->payload, true) : [];
            if ($existing && ($payload['custom'] ?? false) !== true) {
                abort(403);
            }
            $id = $existing?->id ?? (string) Str::ulid();
            $key = $existing?->key ?? 'custom_'.strtolower($id);
            $payload = array_replace($payload ?: [], ['id' => $key, 'name' => $input['name'], 'type' => $input['type'], 'behavior' => $input['behavior'], 'first_message' => $input['first_message'] ?? '', 'expect' => array_values(array_unique($input['expect'])), 'max_turns' => (int) $input['max_turns'], 'custom' => true]);
            DB::table('seller_personas')->updateOrInsert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId],
                ['key' => $key, 'name' => $input['name'], 'type' => $input['type'], 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'max_turns' => (int) $input['max_turns'], 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry($existing ? 'seller.scenario.updated' : 'seller.scenario.created', 'seller_persona', $id));
        });

        return back()->with('status', 'Сценарий сохранён.');
    }
}
