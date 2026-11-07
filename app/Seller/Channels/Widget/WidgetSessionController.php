<?php

namespace App\Seller\Channels\Widget;

use App\Seller\Runtime\WidgetInboundRuntime;
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
            'widget_id' => $widget->id, 'origin' => $origin, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return $this->response(response()->json(['token' => $token], 201), $origin);
    }

    public function message(Request $request): mixed
    {
        [$widget, $origin] = self::widget($request);
        $validator = Validator::make($request->all(), ['token' => ['required', 'string', 'size:64'], 'text' => ['required', 'string', 'max:4000'],
            'idempotency_key' => ['required', 'string', 'max:128'], 'page' => ['sometimes', 'string', 'in:home,catalog,contacts']]);
        if ($validator->fails()) {
            return $this->response(response()->json(['message' => 'Проверьте сообщение.'], 422), $origin);
        }
        $session = self::session($widget, $origin, $request->input('token'));
        if (! $session) {
            return $this->response(response()->json(['message' => 'Сессия недоступна.'], 404), $origin);
        }
        $page = $request->input('page', 'home');
        if ($session->page === null) {
            $paths = ['home' => '/', 'catalog' => '/catalog', 'contacts' => '/contacts'];
            DB::table('widget_sessions')->where('id', $session->id)->update(['page' => $paths[$page] ?? '/', 'updated_at' => now()]);
        }
        $result = app(WidgetInboundRuntime::class)->turn($request, $widget, $session, trim($request->input('text')),
            $request->input('idempotency_key'), $page);
        if (! in_array($result['status'], ['suppressed', 'completed', 'suggested'], true)) {
            return $this->response(response()->json(['message' => 'Чат временно недоступен.'], 503), $origin);
        }
        $body = ['ok' => true];
        if ($result['status'] === 'completed' && is_string($result['reply'] ?? null)) {
            $body['reply'] = $result['reply'];
        }

        return $this->response(response()->json($body), $origin)->setStatusCode(201);
    }

    public function messages(Request $request): mixed
    {
        [$widget, $origin] = self::widget($request);
        $validator = Validator::make($request->all(), ['token' => ['required', 'string', 'size:64'], 'after' => ['nullable', 'string', 'size:26']]);
        if ($validator->fails()) {
            return $this->response(response()->json(['message' => 'Проверьте запрос.'], 422), $origin);
        }
        $session = self::session($widget, $origin, $request->input('token'));
        if (! $session) {
            return $this->response(response()->json(['message' => 'Сессия недоступна.'], 404), $origin);
        }
        if (! $session->conversation_id) {
            return $this->response(response()->json(['messages' => []]), $origin);
        }
        $query = DB::table('messages')->where('organization_id', $widget->organization_id)->where('brand_id', $widget->brand_id)
            ->where('conversation_id', $session->conversation_id)->whereIn('role', ['manager', 'agent']);
        $after = $request->input('after');
        if (is_string($after) && $after !== '') {
            $cursor = DB::table('messages')->where('conversation_id', $session->conversation_id)->where('id', $after)->first(['created_at']);
            if ($cursor) {
                $query->where(function ($q) use ($cursor, $after): void {
                    $q->where('created_at', '>', $cursor->created_at)
                        ->orWhere(function ($q2) use ($cursor, $after): void {
                            $q2->where('created_at', $cursor->created_at)->where('id', '>', $after);
                        });
                });
            }
        }
        $messages = $query->orderBy('created_at')->orderBy('id')->limit(20)->get(['id', 'text']);

        return $this->response(response()->json(['messages' => $messages->map(fn ($m) => ['id' => $m->id, 'text' => $m->text])->all()]), $origin);
    }
}
