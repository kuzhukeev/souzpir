<?php

namespace App\Seller\Channels\Email;

use App\Ai\CanonicalJson;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;

final class ProtectedPriceSender
{
    /** Called only after the protected gateway has validated the tool call. */
    public function send(TenantContext $tenant, TurnClaim $claim, string $responder, string $toolId, array $arguments): array
    {
        if ($tenant->organizationId !== $claim->organizationId || $tenant->brandId !== $claim->brandId) {
            return ['status' => 'unavailable'];
        }

        return app(PostgresTransactionalIdempotency::class)->execute($claim->organizationId, $claim->brandId, 'seller.fake_price_delivery',
            $responder.':'.$toolId, CanonicalJson::hash(['turn' => $claim->turnId, 'responder' => $responder, 'tool' => $toolId, 'arguments' => $arguments]),
            fn (): array => $this->deliver($tenant, $claim, $arguments));
    }

    private function deliver(TenantContext $tenant, TurnClaim $claim, array $arguments): array
    {
        $candidate = app(ToolRunner::class)->run('send_price', $arguments, $tenant);
        $mailer = config('mail.default');
        $fakeMail = Mail::getFacadeRoot() instanceof MailFake || (is_string($mailer) && config('mail.mailers.'.$mailer.'.transport') === 'array');
        $recipient = $arguments['email'] ?? null;
        if (($candidate['status'] ?? null) === 'fake_sent' && config('runtime.ai_driver') === 'fake' && $fakeMail
            && is_string($recipient) && app(PriceMailSender::class)->send($tenant, $recipient)) {
            return $candidate;
        }
        $exists = DB::table('seller_tasks')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('conversation_id', $claim->conversationId)->where('type', 'price_send_failed')->where('payload->turn_id', $claim->turnId)->exists();
        if (! $exists) {
            DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'conversation_id' => $claim->conversationId, 'type' => 'price_send_failed', 'payload' => json_encode(['turn_id' => $claim->turnId, 'text' => 'Не удалось отправить прайс. Проверьте настройки и свяжитесь с клиентом.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => 'new', 'priority' => 'normal', 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['status' => 'unavailable'];
    }
}
