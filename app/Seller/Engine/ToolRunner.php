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
            if ($name !== 'calc_delivery' && $name !== 'check_stock' && $name !== 'send_price') {
                return ['status' => 'unavailable'];
            }
            if (($name === 'check_stock' || $name === 'send_price') && ! $config instanceof TenantContext) {
                return ['status' => 'unavailable'];
            }
            if (! (new ToolArguments)->check($name, $arguments)['valid']) {
                return ['status' => 'invalid_arguments'];
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
            $file = $scope('catalog_settings')->first(['price_file_path', 'price_file_name']);

            return $enabled && $file?->price_file_path ? ['status' => 'fake_sent', 'file' => $file->price_file_name] : ['status' => 'unavailable'];
        }
        $query = trim($arguments['query']);
        if (mb_strlen($query) < 2) {
            return ['status' => 'invalid_arguments'];
        }
        $import = $scope('catalog_imports')->where('status', 'completed')->orderByDesc('created_at')->orderByDesc('id')->value('id');
        if (! $import) {
            return ['status' => 'unavailable'];
        }
        $items = $scope('catalog_items')->where('import_id', $import)->where('sku', $query)
            ->orderBy('name')->limit(5)->get(['sku', 'name', 'stock']);
        if ($items->isEmpty()) {
            $items = $scope('catalog_items')->where('import_id', $import)->where('name', 'ilike', '%'.$query.'%')
                ->orderBy('name')->limit(5)->get(['sku', 'name', 'stock']);
        }

        return ['status' => 'completed', 'items' => $items->map(fn ($item) => ['sku' => $item->sku, 'name' => $item->name,
            'stock' => $item->stock === null ? null : (float) $item->stock])->all()];
    }
}
