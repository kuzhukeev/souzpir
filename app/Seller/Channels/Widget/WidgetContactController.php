<?php

namespace App\Seller\Channels\Widget;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class WidgetContactController
{
    private function widget(Request $request): array
    {
        $key = $request->query('key');
        $origin = $request->header('Origin');
        abort_unless(is_string($key) && strlen($key) === 64 && is_string($origin), 404);
        $row = DB::table('widget_settings')->where('public_key', $key)->where('enabled', true)->first();
        abort_unless($row && in_array(strtolower($origin), json_decode($row->origins, true) ?? [], true), 404);

        return [$row, $origin];
    }

    private function cors(mixed $response, string $origin): mixed
    {
        return $response->header('Access-Control-Allow-Origin', $origin)->header('Vary', 'Origin')->header('Cache-Control', 'no-store');
    }

    public function preflight(Request $request): mixed
    {
        [, $origin] = $this->widget($request);

        return $this->cors(response('', 204), $origin)->header('Access-Control-Allow-Methods', 'POST, OPTIONS')->header('Access-Control-Allow-Headers', 'Content-Type');
    }

    public function store(Request $request): mixed
    {
        [$row, $origin] = $this->widget($request);
        $behavior = json_decode($row->behavior, true) ?: [];
        abort_unless(($behavior['collect_contact'] ?? false) && is_string($behavior['consent_url'] ?? null) && filter_var($behavior['consent_url'], FILTER_VALIDATE_URL), 404);
        $validator = Validator::make($request->all(), ['consent' => ['accepted'], 'name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:254', 'required_without:phone'], 'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9 ()-]{6,32}$/', 'required_without:email']]);
        if ($validator->fails()) {
            return $this->cors(response()->json(['message' => 'Проверьте контакт и согласие.'], 422), $origin);
        }
        $input = $validator->validated();
        DB::transaction(function () use ($row, $origin, $input, $behavior): void {
            $id = (string) Str::ulid();
            DB::table('widget_contact_requests')->insert(['id' => $id, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id,
                'widget_id' => $row->id, 'origin' => $origin, 'name' => $input['name'] ?? null, 'email' => $input['email'] ?? null, 'phone' => $input['phone'] ?? null,
                'consent_url' => $behavior['consent_url'], 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $channel = DB::table('channel_accounts')->where('organization_id', $row->organization_id)->where('brand_id', $row->brand_id)
                ->where('type', 'widget')->where('settings->widget_id', $row->id)->first();
            if (! $channel) {
                $channelId = (string) Str::ulid();
                DB::table('channel_accounts')->insert(['id' => $channelId, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id,
                    'type' => 'widget', 'name' => 'Сайт', 'status' => 'active', 'default_mode' => 'manager',
                    'settings' => json_encode(['widget_id' => $row->id], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $channelId = $channel->id;
            }
            $contactId = (string) Str::ulid();
            $dialogId = (string) Str::ulid();
            DB::table('contacts')->insert(['id' => $contactId, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id,
                'name' => $input['name'] ?? null, 'email' => $input['email'] ?? null, 'phone' => $input['phone'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->insert(['id' => $dialogId, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id,
                'channel_account_id' => $channelId, 'contact_id' => $contactId, 'mode' => 'manager', 'state' => json_encode(['slots' => ['email' => $input['email'] ?? null]], JSON_THROW_ON_ERROR),
                'last_client_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $eventId = (string) Str::ulid();
            DB::table('channel_inbound_events')->insert(['id' => $eventId, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id, 'channel_account_id' => $channelId, 'external_id' => $id, 'payload' => '{}', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => (string) Str::ulid(), 'channel_account_id' => $channelId, 'external_event_id' => $eventId, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id,
                'conversation_id' => $dialogId, 'role' => 'client', 'text' => 'Клиент оставил контакт через виджет сайта.', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record(new TenantContext($row->organization_id, $row->brand_id, source: 'public'), new AuditEntry('seller.widget.contact.received', 'widget_contact_request', $id));
        });

        return $this->cors(response()->json(['ok' => true], 201), $origin);
    }
}
