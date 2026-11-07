<?php

namespace App\Seller\Engine;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Internal, side-effect-free dispatcher only. A caller and supplied data are
 * not proof of authorization, tenant ownership or an approved configuration.
 * This is not the registered ToolHandler/runtime integration.
 */
final class ToolRunner
{
    /** @return array<string, mixed> */
    public function run(#[SensitiveParameter] mixed $name, #[SensitiveParameter] mixed $arguments, #[SensitiveParameter] mixed $config): array
    {
        try {
            if ($name === 'set_followup' && is_array($config) && ($config['now'] ?? null) instanceof CarbonImmutable) {
                if (! (new ToolArguments)->check($name, $arguments)['valid']) {
                    return ['status' => 'invalid_arguments'];
                }
                $candidate = (new FollowupDate)->parse($arguments['datetime'], $config['now']);
                if ($candidate['status'] !== 'candidate') {
                    return ['status' => 'needs_clarification'];
                }

                return [
                    'status' => 'candidate',
                    'datetime' => $candidate['date']->toIso8601String(),
                    'time_defaulted' => $candidate['time_defaulted'],
                    'day_defaulted' => $candidate['day_defaulted'],
                    'year_defaulted' => $candidate['year_defaulted'],
                ];
            }
            if ($name !== 'calc_delivery' && $name !== 'check_stock' && $name !== 'send_price' && $name !== 'log_unknown_question') {
                return ['status' => 'unavailable'];
            }
            if (($name === 'check_stock' || $name === 'send_price' || $name === 'log_unknown_question') && ! $config instanceof TenantContext) {
                return ['status' => 'unavailable'];
            }
            if (! (new ToolArguments)->check($name, $arguments)['valid']) {
                return ['status' => 'invalid_arguments'];
            }
            if ($name === 'log_unknown_question') {
                // The lead-state record is kept by Policy::applyExtraction, keyed by the goal, not by this call.
                return ['status' => 'logged'];
            }
            if ($name === 'check_stock' || $name === 'send_price') {
                return $config instanceof TenantContext && $config->organizationId && $config->brandId
                    ? $this->catalog($name, $arguments, $config) : ['status' => 'unavailable'];
            }

            // The donor accepts weight_kg but does not use it in its estimate.
            return (new ConfiguredDeliveryEstimate)->calculate($arguments['city'], $config);
        } catch (Throwable) {
            // Never include caller data or parser errors in a failure result.
            return ['status' => 'unavailable'];
        }
    }

    private function catalog(string $name, array $arguments, TenantContext $tenant): array
    {
        $scope = fn (string $table) => DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
        if ($name === 'send_price') {
            $email = trim((string) ($arguments['email'] ?? ''));
            $messenger = trim((string) ($arguments['messenger'] ?? ''));
            if (($email === '' && $messenger === '') || ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
                return ['status' => 'invalid_arguments'];
            }
            $enabled = $scope('brand_seller_settings')->value('catalog_prices_enabled');
            if (! $enabled) {
                return ['status' => 'unavailable'];
            }
            $raw = $scope('seller_tool_preferences')->value('payload');
            $preferences = $raw ? json_decode($raw, true) : [];
            $preferences = is_array($preferences) ? $preferences : [];
            $source = $preferences['price_source'] ?? 'file';
            if ($source === 'link') {
                $url = $preferences['price_url'] ?? null;

                return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
                    ? ['status' => 'fake_sent', 'source' => 'link'] : ['status' => 'unavailable'];
            }
            if ($source === 'catalog') {
                $import = $scope('catalog_imports')->where('status', 'completed')->orderByDesc('created_at')->orderByDesc('id')->value('id');
                $available = $import && $scope('catalog_items')->where('import_id', $import)->whereNotNull('price')->exists();

                return $available ? ['status' => 'fake_sent', 'source' => 'catalog'] : ['status' => 'unavailable'];
            }
            if ($source !== 'file') {
                return ['status' => 'unavailable'];
            }
            $file = $scope('catalog_settings')->first(['price_file_path', 'price_file_name']);

            return $file?->price_file_path ? ['status' => 'fake_sent', 'file' => $file->price_file_name] : ['status' => 'unavailable'];
        }
        $query = trim($arguments['query']);
        if (mb_strlen($query) < 2) {
            return ['status' => 'invalid_arguments'];
        }
        $import = $scope('catalog_imports')->where('status', 'completed')->orderByDesc('created_at')->orderByDesc('id')->value('id');
        if (! $import) {
            return ['status' => 'unavailable'];
        }
        $columns = ['sku', 'name', 'stock', 'price', 'wholesale_price'];
        $items = $scope('catalog_items')->where('import_id', $import)->where('sku', $query)
            ->orderBy('name')->limit(5)->get($columns);
        if ($items->isEmpty()) {
            $items = $scope('catalog_items')->where('import_id', $import)->where('name', 'ilike', '%'.$query.'%')
                ->orderBy('name')->limit(5)->get($columns);
        }
        $sellerSettings = $scope('brand_seller_settings')->first(['catalog_prices_enabled', 'tools']);
        $pricesEnabled = (bool) ($sellerSettings->catalog_prices_enabled ?? false);
        $tools = $sellerSettings && $sellerSettings->tools ? json_decode($sellerSettings->tools, true) : [];
        $discountsEnabled = is_array($tools) && ($tools['catalog_discounts_enabled'] ?? false);

        return ['status' => 'completed', 'empty' => $items->isEmpty(), 'items' => $items->map(function ($item) use ($pricesEnabled, $discountsEnabled): array {
            $out = ['sku' => $item->sku, 'name' => $item->name, 'stock' => $item->stock === null ? null : (float) $item->stock];
            if ($pricesEnabled && $item->price !== null) {
                $out['price'] = (float) $item->price;
            }
            if ($discountsEnabled && $item->wholesale_price !== null) {
                $out['wholesale_price'] = (float) $item->wholesale_price;
            }

            return $out;
        })->all()];
    }
}
