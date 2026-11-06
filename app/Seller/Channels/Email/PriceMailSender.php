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
        $settings = DB::table('seller_email_price_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('enabled', true)->first();
        $file = DB::table('catalog_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first(['price_file_path', 'price_file_name']);
        if (! $settings || ! filter_var($settings->sender_email, FILTER_VALIDATE_EMAIL) || ! is_string($settings->sender_name) || trim($settings->sender_name) === '' || ! $file?->price_file_path || ! $file->price_file_name || ! Storage::disk('local')->exists($file->price_file_path)) {
            return false;
        }
        try {
            Mail::to($recipient)->send(new PriceMail($file->price_file_path, $file->price_file_name, $settings->sender_email, $settings->sender_name));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
