<?php

namespace App\Seller\Channels\Telegram;

use App\Models\User;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Vision\SiteAudit\PublicUrlPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TelegramRegistrationController
{
    public function __invoke(Request $request): mixed
    {
        $account = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant): ChannelAccount {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return ChannelAccount::query()->where('type', 'telegram')->where('status', 'active')->firstOrFail();
        });
        $credentials = $account->credentials ?? [];
        $token = $credentials['token'] ?? null;
        $secret = $credentials['webhook_secret'] ?? null;
        try {
            $url = app(PublicUrlPolicy::class)->normalize(rtrim((string) config('app.url'), '/').route('seller.telegram.webhook', $account->id, false));
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($token) || ! is_string($secret)) {
                throw new \LogicException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['webhook' => 'Нужен публичный HTTPS-адрес приложения и настроенный токен бота.']);
        }
        $ok = app(TelegramTransport::class)->register($token, $url, $secret);
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($account, $ok): void {
            Gate::forUser($user)->authorize('seller.settings.write');
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.telegram.webhook.registration', 'channel_account', $account->id, after: ['success' => $ok]));
        });

        return $ok ? back()->with('status', 'Вебхук зарегистрирован.') : back()->withErrors(['webhook' => 'Не удалось зарегистрировать вебхук.']);
    }
}
