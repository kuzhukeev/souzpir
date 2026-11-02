<?php

namespace App\Seller\Channels\Widget;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PublicWidgetController
{
    public function loader(): mixed
    {
        return response(file_get_contents(base_path('resources/widget/widget.js')), 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function config(Request $request): mixed
    {
        $key = $request->query('key');
        $origin = $request->header('Origin');
        if (! is_string($origin)) {
            $referer = parse_url((string) $request->header('Referer'));
            if (is_array($referer) && isset($referer['scheme'], $referer['host'])) {
                $origin = $referer['scheme'].'://'.$referer['host'].(isset($referer['port']) ? ':'.$referer['port'] : '');
            }
        }
        abort_unless(is_string($key) && strlen($key) === 64 && is_string($origin), 404);
        $row = DB::table('widget_settings')->where('public_key', $key)->where('enabled', true)->first();
        abort_unless($row && in_array(strtolower($origin), json_decode($row->origins, true) ?? [], true), 404);
        DB::table('widget_settings')->where('id', $row->id)->update(['last_seen_at' => now()]);

        return response()->json(['appearance' => json_decode($row->appearance, true), 'behavior' => json_decode($row->behavior, true)])
            ->header('Access-Control-Allow-Origin', $origin)->header('Vary', 'Origin')->header('Cache-Control', 'no-store');
    }
}
