<?php

namespace App\Seller\Followups;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FollowupConsent
{
    public function record(TenantContext $tenant, string $conversation, string $event, string $source, string $basis, ?string $message = null, ?string $actor = null): void
    {
        DB::table('seller_followup_consents')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'conversation_id' => $conversation, 'event' => $event, 'source' => $source,
            'message_id' => $message, 'actor_id' => $actor, 'basis' => $basis,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function allowed(TenantContext $tenant, string $conversation): bool
    {
        return $this->latest($tenant, $conversation) === 'granted';
    }

    public function revoked(TenantContext $tenant, string $conversation): bool
    {
        return $this->latest($tenant, $conversation) === 'revoked';
    }

    private function latest(TenantContext $tenant, string $conversation): ?string
    {
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $contact = DB::table('conversations')->where($scope)->where('id', $conversation)->value('contact_id');
        $events = DB::table('seller_followup_consents')->where($scope);
        if ($contact) {
            $events->whereIn('conversation_id', DB::table('conversations')->where($scope)->where('contact_id', $contact)->select('id'));
        } else {
            $events->where('conversation_id', $conversation);
        }

        return $events->orderByDesc('sequence')->value('event');
    }

    public function inbound(TenantContext $tenant, string $conversation, string $message, string $text): void
    {
        $text = mb_strtolower(trim($text));
        if (preg_match('/(?:^|[^\\p{L}])(?:стоп|отпишись|не пишите|отписаться)(?:$|[^\\p{L}])/u', $text)) {
            $this->record($tenant, $conversation, 'revoked', 'dialog', $text, $message);

            return;
        }
        if (! preg_match('/^да[.! ]*$/u', $text)) {
            return;
        }
        $previous = DB::table('messages')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('conversation_id', $conversation)->where('role', 'agent')->orderByDesc('created_at')->orderByDesc('id')->value('text');
        if (is_string($previous) && preg_match('/(?:напоминан|предложени|рассылк)/ui', $previous)
            && str_contains($previous, '?')) {
            $this->record($tenant, $conversation, 'granted', 'dialog', $previous, $message);
        }
    }
}
