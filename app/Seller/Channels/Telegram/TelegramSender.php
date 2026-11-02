<?php

namespace App\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;

final class TelegramSender
{
    public function send(TenantContext $tenant, string $accountId, string $chatId, #[\SensitiveParameter] string $text): bool
    {
        if (! $tenant->organizationId || ! $tenant->brandId || ! preg_match('/^-?[0-9]{1,20}$/D', $chatId) || trim($text) === '' || mb_strlen($text) > 4096) {
            return false;
        }
        if (! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            return false;
        }

        return app(TenantContextRunner::class)->run($tenant, function () use ($accountId, $chatId, $text): bool {
            $account = ChannelAccount::query()->whereKey($accountId)->where('type', 'telegram')->where('status', 'active')->first();
            $token = $account?->credentials['token'] ?? null;

            return is_string($token) && app(TelegramTransport::class)->send($token, $chatId, $text);
        });
    }

    public function managers(TenantContext $tenant, string $accountId, #[\SensitiveParameter] string $text): bool
    {
        if (! $tenant->organizationId || ! $tenant->brandId) {
            return false;
        }
        $chat = app(TenantContextRunner::class)->run($tenant, fn () => ChannelAccount::query()->whereKey($accountId)->where('type', 'telegram')->where('status', 'active')->first()?->managers_chat_id);

        return is_string($chat) && $this->send($tenant, $accountId, $chat, $text);
    }
}
