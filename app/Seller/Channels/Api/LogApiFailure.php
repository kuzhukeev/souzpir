<?php

namespace App\Seller\Channels\Api;

use App\Tenancy\TokenApi\BrandAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class LogApiFailure
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }
        $endpoint = match ($request->route()?->getName()) {
            'api.v1.leads.create' => 'leads', 'api.v1.turn.create' => 'turn', default => null,
        };
        if (! $endpoint) {
            return;
        }
        $token = $request->attributes->get('tenant.token');
        $verified = $token instanceof BrandAccessToken;
        $category = match (true) {
            $status === 401 => 'unauthorized', $status === 403 => 'forbidden', $status === 422 => 'validation', $status === 409 => 'conflict', $status >= 500 => 'failure', default => 'rejected'
        };
        DB::transaction(function () use ($request, $token, $verified, $endpoint, $status, $category): void {
            $id = (string) Str::ulid();
            DB::table('seller_api_request_failures')->insert(['id' => $id, 'organization_id' => $verified ? $token->organization_id : null,
                'brand_id' => $verified ? $token->brand_id : null, 'endpoint' => $endpoint, 'http_status' => $status, 'category' => $category, 'created_at' => now()]);
            $source = $request->input('source');
            if ($verified && in_array($source, ['site', 'crm'], true)) {
                app(ApiSourceReceipt::class)->save($token->organization_id, $token->brand_id, $id, $source);
            }
        });
    }
}
