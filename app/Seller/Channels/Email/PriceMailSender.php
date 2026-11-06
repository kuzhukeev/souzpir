<?php

namespace App\Seller\Channels\Email;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PriceMailSender
{
    /** Returns false for the existing manager-task fallback. */
    public function send(TenantContext $tenant, string $recipient): bool
    {
        if (! config('seller.email_price_enabled', false) || ! $tenant->organizationId || ! $tenant->brandId || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $settings = DB::table('seller_email_price_settings')->where($scope)->where('enabled', true)->first();
        if (! $settings || ! filter_var($settings->sender_email, FILTER_VALIDATE_EMAIL) || ! is_string($settings->sender_name) || trim($settings->sender_name) === '') {
            return false;
        }
        $raw = DB::table('seller_tool_preferences')->where($scope)->value('payload');
        $preferences = $raw ? json_decode($raw, true) : [];
        $preferences = is_array($preferences) ? $preferences : [];
        $source = $preferences['price_source'] ?? 'file';
        $path = '';
        $filename = 'price.csv';
        $link = null;
        $csv = null;
        if ($source === 'file') {
            $file = DB::table('catalog_settings')->where($scope)->first(['price_file_path', 'price_file_name']);
            if (! $file?->price_file_path || ! $file->price_file_name || ! Storage::disk('local')->exists($file->price_file_path)) {
                return false;
            }
            $path = $file->price_file_path;
            $filename = $file->price_file_name;
        } elseif ($source === 'link') {
            $link = $preferences['price_url'] ?? null;
            if (! is_string($link) || ! filter_var($link, FILTER_VALIDATE_URL) || parse_url($link, PHP_URL_SCHEME) !== 'https') {
                return false;
            }
        } elseif ($source === 'catalog') {
            $import = DB::table('catalog_imports')->where($scope)->where('status', 'completed')->orderByDesc('created_at')->orderByDesc('id')->value('id');
            $items = $import ? DB::table('catalog_items')->where($scope)->where('import_id', $import)->whereNotNull('price')->orderBy('name')->get(['sku', 'name', 'price']) : collect();
            if ($items->isEmpty()) {
                return false;
            }
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, ['Артикул', 'Наименование', 'Цена'], ';', '"', '');
            foreach ($items as $item) {
                fputcsv($stream, [$item->sku, $item->name, $item->price], ';', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            if ($csv === false) {
                return false;
            }
        } else {
            return false;
        }
        try {
            Mail::to($recipient)->send(new PriceMail($path, $filename, $settings->sender_email, $settings->sender_name, priceUrl: $link, catalogCsv: $csv));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
