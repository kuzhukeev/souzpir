<?php

namespace App\Seller\Catalog;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\SiteAudit\PublicUrlPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class YmlRefresh
{
    public function run(TenantContext $tenant, array $settings): bool
    {
        $id = (string) Str::ulid();
        try {
            $target = app(PublicUrlPolicy::class)->target($settings['url']);
            if (parse_url($target['url'], PHP_URL_SCHEME) !== 'https' || ! extension_loaded('curl')) {
                throw new \RuntimeException;
            }
            $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(15)->withOptions([
                'proxy' => '', 'verify' => true, 'cookies' => false,
                'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$address], CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true],
                'on_headers' => static function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 5000000) {
                        throw new \RuntimeException;
                    }
                },
            ])->get($target['url']);
            if (! $response->successful()) {
                throw new \RuntimeException;
            }
            $items = app(YmlReader::class)->parse($response->body());
            DB::transaction(function () use ($tenant, $settings, $id, $items): void {
                DB::table('catalog_imports')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'filename' => 'YML', 'status' => 'completed', 'item_count' => count($items), 'created_by' => $settings['user_id'], 'created_at' => now(), 'updated_at' => now()]);
                foreach ($items as $item) {
                    DB::table('catalog_items')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                        'brand_id' => $tenant->brandId, 'import_id' => $id, ...$item, 'created_at' => now(), 'updated_at' => now()]);
                }
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.catalog.yml.imported', 'catalog_import', $id, after: ['item_count' => count($items)]));
            });

            return true;
        } catch (Throwable) {
            DB::table('catalog_imports')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'filename' => 'YML', 'status' => 'failed', 'item_count' => 0, 'error' => 'Не удалось обновить YML. Проверьте адрес и формат.',
                'created_by' => $settings['user_id'], 'created_at' => now(), 'updated_at' => now()]);

            return false;
        }
    }
}
