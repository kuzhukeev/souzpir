<?php

namespace App\Seller\Knowledge\Prohibitions;

use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class GuardHitRecorder
{
    public function record(TenantContext $tenant, string $responderId, KnowledgeSnapshot $knowledge, string $text, array $result): void
    {
        $messages = [...($result['issues'] ?? []), ...($result['warnings'] ?? [])];
        foreach ($knowledge->guardRules()['forbidden_promises'] ?? [] as $rule) {
            if (($rule['enabled'] ?? true) === false || ! is_string($rule['id'] ?? null)
                || ! in_array('запрещённое обещание: '.($rule['description'] ?? ''), $messages, true)) {
                continue;
            }
            $allowed = false;
            foreach ($rule['allow_if'] ?? [] as $allow) {
                $allowed = $allowed || str_contains(mb_strtolower($text), mb_strtolower($allow));
            }
            if ($allowed) {
                continue;
            }
            foreach ($rule['patterns'] ?? [] as $pattern) {
                if (preg_match('~'.str_replace('~', '\~', $pattern).'~ium', $text) === 1) {
                    DB::table('prohibition_guard_hits')->insertOrIgnore([
                        'organization_id' => $tenant->organizationId,
                        'brand_id' => $tenant->brandId,
                        'responder_id' => $responderId,
                        'rule_id' => $rule['id'],
                        'created_at' => now(),
                    ]);
                    break;
                }
            }
        }
    }
}
