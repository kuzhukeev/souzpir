<?php

namespace App\Seller\Channels\Api;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Engine\LeadState;
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
        $input = $request->validate(['fields' => ['required', 'array', 'min:1', 'max:50'], 'fields.*' => ['nullable', 'string', 'max:500'], 'action' => ['sometimes', 'in:card_only,open']]);
        foreach (array_keys($input['fields']) as $field) {
            if (! is_string($field) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $field)) {
                throw ValidationException::withMessages(['fields' => 'Недопустимое имя поля.']);
            }
        }
        $hash = CanonicalJson::hash(['fields' => $input['fields'], 'action' => $input['action'] ?? 'card_only']);
        $existing = DB::table('seller_api_lead_requests')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('endpoint', 'leads')->where('idempotency_key', $key)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency key was used with different content.');

            return response()->json(['contact_id' => $existing->contact_id, 'conversation_id' => $existing->conversation_id, 'status' => $existing->status], 200);
        }
        if (($input['action'] ?? 'card_only') === 'open') {
            return response()->json(['message' => 'Открытие диалога пока недоступно.'], 503);
        }
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
            'status' => 'created', 'created_at' => now(), 'updated_at' => now()]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.api.lead.created', 'contact', $contact));

        return response()->json(['contact_id' => $contact, 'conversation_id' => $conversation, 'status' => 'created'], 201);
    }

    public function turn(#[SensitiveParameter] Request $request, TenantContext $tenant): mixed
    {
        abort_unless($tenant->source === 'token' && $tenant->organizationId && $tenant->brandId, 401);
        abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Укажите корректный ключ идемпотентности.']);
        }
        $input = $request->validate(['conversation_id' => ['required', 'ulid'], 'text' => ['required', 'string', 'max:5000']]);
        if (trim($input['text']) === '') {
            throw ValidationException::withMessages(['text' => 'Введите сообщение.']);
        }
        try {
            return response()->json(app(TokenInboundRuntime::class)->turn($request, $tenant, $input['conversation_id'], $input['text'], $key));
        } catch (IdempotencyConflict) {
            return response()->json(['message' => 'Ключ уже использован для другого запроса.'], 409);
        } catch (IdempotencyFailure|\LogicException) {
            return response()->json(['message' => 'Обработка хода временно недоступна.'], 503);
        }
    }
}
