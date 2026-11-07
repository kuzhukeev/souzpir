<?php

namespace App\Seller\Channels\Api;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Runtime\ApiFollowupRuntime;
use App\Seller\Runtime\ApiOpeningRuntime;
use App\Seller\Runtime\TokenInboundRuntime;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class LeadIngressController
{
    public const DEFAULT_MAPPING = ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'city' => 'city', 'purpose' => 'purpose'];

    public const TARGETS = ['name', 'email', 'phone', 'city', 'purpose', 'experience', 'outlet_type', 'current_supplier', 'budget_range', 'timing', 'messenger'];

    public function lead(#[SensitiveParameter] Request $request, TenantContext $tenant): mixed
    {
        abort_unless($tenant->source === 'token' && $tenant->organizationId && $tenant->brandId, 401);
        abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Укажите корректный ключ идемпотентности.']);
        }
        $input = $request->validate(['fields' => ['required', 'array', 'min:1', 'max:50'], 'fields.*' => ['nullable', 'string', 'max:500'], 'action' => ['sometimes', 'in:card_only,open'], 'source' => ['sometimes', 'in:site,crm']]);
        $input['action'] ??= $request->attributes->get('seller.api.action', 'card_only');
        if (! in_array($input['action'], ['card_only', 'open'], true)) {
            throw new \LogicException('Invalid saved API action.');
        }
        foreach (array_keys($input['fields']) as $field) {
            if (! is_string($field) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $field)) {
                throw ValidationException::withMessages(['fields' => 'Недопустимое имя поля.']);
            }
        }
        $hashInput = ['fields' => $input['fields'], 'action' => $input['action'] ?? 'card_only'];
        if (isset($input['source'])) {
            $hashInput['source'] = $input['source'];
        }
        $hash = CanonicalJson::hash($hashInput);
        if (($input['action'] ?? 'card_only') === 'open') {
            if (config('runtime.ai_driver', 'fake') !== 'fake') {
                return response()->json(['message' => 'Открытие диалога пока недоступно.'], 503);
            }
            try {
                [$result, $created] = app(ApiOpeningRuntime::class)->open($request, $tenant, $input, $key, $hash);

                return response()->json($result, $created ? 201 : 200);
            } catch (IdempotencyConflict) {
                return response()->json(['message' => 'Ключ уже использован для другого запроса.'], 409);
            } catch (IdempotencyFailure|KnowledgeNotReady|\LogicException) {
                return response()->json(['message' => 'Открытие диалога временно недоступно.'], 503);
            }
        }
        $existing = DB::table('seller_api_lead_requests')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('endpoint', 'leads')->where('idempotency_key', $key)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency key was used with different content.');

            return response()->json(['contact_id' => $existing->contact_id, 'conversation_id' => $existing->conversation_id, 'status' => $existing->status], 200);
        }
        $lead = $this->createMappedLead($tenant, $input, $key, $hash, 'created');

        return response()->json(['contact_id' => $lead['contact_id'], 'conversation_id' => $lead['conversation_id'], 'status' => 'created'], 201);
    }

    /** The caller owns the transaction and must validate token authority. */
    public function createMappedLead(TenantContext $tenant, array $input, string $key, string $hash, string $status): array
    {
        $raw = DB::table('seller_api_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('mapping');
        $mapping = $raw ? json_decode($raw, true) : self::DEFAULT_MAPPING;
        $mapped = [];
        foreach ($mapping as $external => $target) {
            if (in_array($target, self::TARGETS, true) && isset($input['fields'][$external])) {
                $mapped[$target] = trim($input['fields'][$external]);
            }
        }
        Validator::make($mapped, ['name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:254']])->validate();
        if (empty($mapped['name']) && empty($mapped['phone']) && empty($mapped['email'])) {
            throw ValidationException::withMessages(['fields' => 'Укажите имя, телефон или почту.']);
        }
        $contact = (string) Str::ulid();
        $conversation = (string) Str::ulid();
        $id = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'name' => $mapped['name'] ?? null, 'phone' => $mapped['phone'] ?? null, 'email' => $mapped['email'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $state = new LeadState;
        foreach ($mapped as $slot => $value) {
            if ($slot !== 'phone' && in_array($slot, LeadState::SLOTS, true)) {
                $state->setSlot($slot, $value);
            }
        }
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'contact_id' => $contact,
            'state' => json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_api_lead_requests')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'endpoint' => 'leads', 'idempotency_key' => $key, 'request_hash' => $hash, 'contact_id' => $contact, 'conversation_id' => $conversation,
            'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        if (isset($input['source'])) {
            app(ApiSourceReceipt::class)->save($tenant->organizationId, $tenant->brandId, $id, $input['source']);
        }
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.api.lead.created', 'contact', $contact));

        return ['id' => $id, 'contact_id' => $contact, 'conversation_id' => $conversation];
    }

    public function turn(#[SensitiveParameter] Request $request, TenantContext $tenant): mixed
    {
        abort_unless($tenant->source === 'token' && $tenant->organizationId && $tenant->brandId, 401);
        abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Укажите корректный ключ идемпотентности.']);
        }
        $input = $request->validate(['conversation_id' => ['required', 'ulid'], 'text' => ['required', 'string', 'max:5000'], 'source' => ['sometimes', 'in:site,crm']]);
        if (trim($input['text']) === '') {
            throw ValidationException::withMessages(['text' => 'Введите сообщение.']);
        }
        try {
            $runtime = $request->attributes->get('seller.api.two_phase_turn') ? app(ApiFollowupRuntime::class) : app(TokenInboundRuntime::class);

            return response()->json($runtime->turn($request, $tenant, $input['conversation_id'], $input['text'], $key, $input['source'] ?? null));
        } catch (IdempotencyConflict) {
            return response()->json(['message' => 'Ключ уже использован для другого запроса.'], 409);
        } catch (IdempotencyFailure|KnowledgeNotReady|\LogicException) {
            return response()->json(['message' => 'Обработка хода временно недоступна.'], 503);
        }
    }
}
