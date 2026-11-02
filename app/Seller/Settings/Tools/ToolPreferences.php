<?php

namespace App\Seller\Settings\Tools;

use App\Seller\Engine\ToolCatalog;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class ToolPreferences
{
    public const REQUIRED = ['escalate', 'mark_do_not_disturb', 'create_promise', 'log_unknown_question', 'send_requisites'];

    public function defaults(TenantContext $tenant): array
    {
        $reasons = [];
        try {
            $knowledge = app(KnowledgeRepository::class)->published($tenant);
            foreach ($knowledge->all('escalation')['reasons'] ?? [] as $reason) {
                if (! is_string($reason['id'] ?? null)) {
                    continue;
                }
                $reasons[$reason['id']] = ['title' => $reason['title'] ?? $reason['id'], 'enabled' => true,
                    'priority' => $reason['priority'] ?? 'normal', 'message' => $reason['message_idea'] ?? '', 'after' => 'silent'];
            }
        } catch (\Throwable) {
            // A brand may not have published knowledge yet.
        }

        return ['tools' => array_fill_keys(array_keys(ToolCatalog::definitions()), true), 'reasons' => $reasons,
            'delivery_rules' => [], 'requisites' => '', 'timeout_minutes' => null];
    }

    public function read(TenantContext $tenant): array
    {
        $defaults = $this->defaults($tenant);
        $raw = DB::table('seller_tool_preferences')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('payload');
        $saved = $raw ? json_decode($raw, true) : [];
        $saved = is_array($saved) ? $saved : [];
        $defaults['tools'] = array_replace($defaults['tools'], array_intersect_key($saved['tools'] ?? [], $defaults['tools']));
        foreach (self::REQUIRED as $name) {
            $defaults['tools'][$name] = true;
        }
        foreach ($defaults['reasons'] as $id => $reason) {
            $defaults['reasons'][$id] = array_replace($reason, $saved['reasons'][$id] ?? []);
        }
        $defaults['delivery_rules'] = $saved['delivery_rules'] ?? [];
        $defaults['requisites'] = $saved['requisites'] ?? '';
        $defaults['timeout_minutes'] = $saved['timeout_minutes'] ?? null;

        return $defaults;
    }
}
