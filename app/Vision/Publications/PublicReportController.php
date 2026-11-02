<?php

namespace App\Vision\Publications;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class PublicReportController
{
    public function __invoke(string $token): Response
    {
        $row = DB::table('report_publications')->where('token_hash', hash('sha256', $token))
            ->first(['brand_id', 'organization_id', 'public_payload', 'expires_at', 'revoked_at', 'created_at']);
        abort_unless($row, 404);
        $expired = $row->revoked_at !== null || ($row->expires_at !== null && now()->greaterThanOrEqualTo($row->expires_at));
        $brandName = DB::table('brands')->where('id', $row->brand_id)->where('organization_id', $row->organization_id)->value('name');
        $response = response()->view('public-report.index', [
            'expired' => $expired,
            'brandName' => $brandName ?? 'Бренд',
            'report' => $expired ? null : json_decode($row->public_payload, true, 32, JSON_THROW_ON_ERROR),
            'publishedAt' => $row->created_at,
        ], $expired ? 410 : 200);

        return $response->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
