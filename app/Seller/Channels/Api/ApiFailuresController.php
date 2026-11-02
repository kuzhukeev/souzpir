<?php

namespace App\Seller\Channels\Api;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ApiFailuresController
{
    public function __invoke(Request $request): mixed
    {
        $rows = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return DB::table('seller_api_request_failures')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->orderByDesc('created_at')->limit(100)->get(['endpoint', 'http_status', 'created_at']);
        });
        $items = '';
        foreach ($rows as $row) {
            $items .= '<li><strong>'.e($row->endpoint === 'leads' ? 'Создание карточки' : 'Ход диалога').'</strong> · Ошибка '.e($row->http_status).' · '.e($row->created_at).'</li>';
        }
        if ($items === '') {
            $items = '<li>Отклонённых запросов пока нет.</li>';
        }
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ошибки API</title><style>body{font:16px system-ui,sans-serif;background:#f6f7f6;color:#17243a;margin:0}main{max-width:960px;margin:auto;padding:32px 20px}a{color:#245c41}section{background:white;border:1px solid #d3dbd5;border-radius:12px;padding:20px}li{padding:12px 0;border-bottom:1px solid #eee}a{cursor:pointer}</style></head><body><main><a href="/seller/channels/api">← К настройкам API</a><h1>Ошибки API</h1><p>Последние отклонённые и неуспешные запросы текущего бренда.</p><section><ul>'.$items.'</ul></section></main></body></html>';

        return response($html)->header('Cache-Control', 'private, no-store');
    }
}
