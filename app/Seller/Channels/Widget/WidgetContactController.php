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
            'session_token' => ['nullable', 'string', 'size:64'], 'email' => ['nullable', 'email:rfc', 'max:254', 'required_without:phone'], 'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9 ()-]{6,32}$/', 'required_without:email']]);
        if ($validator->fails()) {
            return $this->cors(response()->json(['message' => 'Проверьте контакт и согласие.'], 422), $origin);
        }
        $input = $validator->validated();
        $session = null;
        if (! empty($input['session_token'])) {
            $session = WidgetSessionController::session($row, strtolower($origin), $input['session_token']);
            if (! $session) {
                return $this->cors(response()->json(['message' => 'Сессия недоступна.'], 404), $origin);
            }
        }
        DB::transaction(function () use ($row, $origin, $input, $behavior, $session): void {
            $id = (string) Str::ulid();
            DB::table('widget_contact_requests')->insert(['id' => $id, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id,
                'widget_id' => $row->id, 'origin' => $origin, 'name' => $input['name'] ?? null, 'email' => $input['email'] ?? null, 'phone' => $input['phone'] ?? null,
                'consent_url' => $behavior['consent_url'], 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($session) {
                DB::table('widget_sessions')->where('id', $session->id)->update(['contact_request_id' => $id, 'updated_at' => now()]);
            }
            app(AuditWriter::class)->record(new TenantContext($row->organization_id, $row->brand_id, source: 'public'), new AuditEntry('seller.widget.contact.received', 'widget_contact_request', $id));
        });

        return $this->cors(response()->json(['ok' => true], 201), $origin);
    }
}
