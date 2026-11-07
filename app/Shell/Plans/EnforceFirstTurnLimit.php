<?php

namespace App\Shell\Plans;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Blocks a brand-new bot dialog at the shared /seller/turns/first entry once the monthly plan limit is exhausted; an already-started conversation is never blocked here. */
final class EnforceFirstTurnLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $conversationId = $request->input('conversation_id');
        if (is_string($conversationId) && Str::isUlid($conversationId)) {
            $conversation = DB::table('conversations')->where('id', $conversationId)->first(['organization_id', 'brand_id', 'mode', 'version']);
            if ($conversation && $conversation->mode === 'bot' && (int) $conversation->version === 0 && $conversation->organization_id && $conversation->brand_id) {
                $tenant = new TenantContext($conversation->organization_id, $conversation->brand_id, source: 'public');
                if (app(MonthlyUnits::class)->exhausted($tenant, 'dialog', $conversationId)) {
                    return response()->json(['message' => 'Первый ход временно недоступен: превышен лимит тарифа.'], 422)
                        ->header('Cache-Control', 'private, no-store');
                }
            }
        }

        return $next($request);
    }
}
