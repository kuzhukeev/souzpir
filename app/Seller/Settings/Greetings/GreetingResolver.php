<?php

namespace App\Seller\Settings\Greetings;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class GreetingResolver
{
    private const CHANNELS = ['telegram', 'widget', 'api', 'email', 'whatsapp'];

    public function resolve(TenantContext $tenant, string $channel, string $page = 'home', ?string $name = null, bool $afterHours = false): ?string
    {
        if (! $tenant->organizationId || ! $tenant->brandId || ! in_array($channel, self::CHANNELS, true)) {
            return null;
        }
        $stored = DB::table('brand_seller_settings')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->value('greetings');
        $settings = is_string($stored) ? json_decode($stored, true) : [];
        if (! is_array($settings)) {
            return null;
        }
        $page = in_array($page, ['home', 'catalog', 'contacts'], true) ? $page : 'home';
        $key = $channel === 'widget' && $page !== 'home' ? 'widget_'.$page : $channel;
        $text = $afterHours && is_string($settings['after_hours'] ?? null) && trim($settings['after_hours']) !== ''
            ? $settings['after_hours'] : ($settings[$key] ?? $settings[$channel] ?? null);

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        return str_replace(['{имя}', '{страница}'], [$name ?: 'гость', ['home' => 'Главная', 'catalog' => 'Каталог', 'contacts' => 'Контакты'][$page]], trim($text));
    }
}
