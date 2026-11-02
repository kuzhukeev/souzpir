<?php

namespace App\Seller\Channels\Widget;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class WidgetSessionController
{
    public static function widget(Request $request): array
    {
        $key = $request->query('key');
        $origin = $request->header('Origin');
        abort_unless(is_string($key) && strlen($key) === 64 && is_string($origin), 404);
        $row = DB::table('widget_settings')->where('public_key', $key)->where('enabled', true)->first();
        abort_unless($row && in_array(strtolower($origin), json_decode($row->origins, true) ?? [], true), 404);

        return [$row, strtolower($origin)];
    }

    public static function session(object $widget, string $origin, string $token): ?object
    {
        return DB::table('widget_sessions')->where('widget_id', $widget->id)->where('organization_id', $widget->organization_id)
            ->where('brand_id', $widget->brand_id)->where('origin', $origin)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    private function response(mixed $response, string $origin): mixed
    {
        return $response->header('Access-Control-Allow-Origin', $origin)->header('Vary', 'Origin')->header('Cache-Control', 'no-store');
    }

    public function preflight(Request $request): mixed
    {
        [, $origin] = self::widget($request);

        return $this->response(response('', 204), $origin)->header('Access-Control-Allow-Methods', 'POST, OPTIONS')->header('Access-Control-Allow-Headers', 'Content-Type');
    }

    public function start(Request $request): mixed
    {
        [$widget, $origin] = self::widget($request);
        $token = Str::random(64);
        DB::table('widget_sessions')->insert(['id' => (string) Str::ulid(), 'organization_id' => $widget->organization_id, 'brand_id' => $widget->brand_id,
            'widget_id' => $widget->id, 'origin' => $origin, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return $this->response(response()->json(['token' => $token], 201), $origin);
    }

    public function message(Request $request): mixed
    {
        [$widget, $origin] = self::widget($request);
        $validator = Validator::make($request->all(), ['token' => ['required', 'string', 'size:64'], 'text' => ['required', 'string', 'max:4000'], 'idempotency_key' => ['required', 'string', 'max:128']]);
        if ($validator->fails()) {
            return $this->response(response()->json(['message' => 'Проверьте сообщение.'], 422), $origin);
        }
        if (! self::session($widget, $origin, $request->input('token'))) {
            return $this->response(response()->json(['message' => 'Сессия недоступна.'], 404), $origin);
        }

        // Existing seller runtime requires an authenticated staff session; fail closed until a public adapter exists.
        return $this->response(response()->json(['message' => 'Чат временно недоступен.']), $origin)->setStatusCode(503);
    }
}
