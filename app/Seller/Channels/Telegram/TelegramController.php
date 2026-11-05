<?php

namespace App\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TelegramController
{
    public function index(TenantContext $context): Response
    {
        $this->authorize($context, false);
        $accounts = ChannelAccount::query()->orderBy('name')->get();
        $widget = DB::table('widget_settings')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->first(['enabled', 'origins']);
        $counts = DB::table('conversations')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)
            ->selectRaw('channel_account_id, sum(case when last_client_message_at >= ? then 1 else 0 end) as total, max(last_client_message_at) as last_activity', [now()->subDays(7)])
            ->groupBy('channel_account_id')->get()->keyBy('channel_account_id');

        return Inertia::render('Seller/Channels/Telegram/Index', [
            'channels' => $accounts->map(fn (ChannelAccount $account): array => [
                'id' => $account->id, 'name' => $account->name ?: 'Telegram', 'type' => $account->type,
                'status' => $account->status, 'mode' => $account->default_mode,
                'dialogs7d' => (int) ($counts->get($account->id)?->total ?? 0), 'lastActivity' => $counts->get($account->id)?->last_activity,
            ])->all(),
            'siteOrigins' => json_decode($widget->origins ?? '[]', true) ?: [],
            'siteEnabled' => $widget ? (bool) $widget->enabled : false,
            'canManage' => Gate::allows('seller.settings.write'), 'status' => session('status'),
        ]);
    }

    public function telegram(TenantContext $context): Response
    {
        $this->authorize($context, false);
        $account = ChannelAccount::query()->where('type', 'telegram')->first();

        return Inertia::render('Seller/Channels/Telegram/Settings', [
            'account' => $account ? [
                'id' => $account->id, 'name' => $account->name, 'status' => $account->status,
                'mode' => $account->default_mode, 'tokenConfigured' => filled($account->credentials['token'] ?? null),
                'managersChat' => $account->managers_chat_id, 'webhookUrl' => route('seller.telegram.webhook', $account->id),
                'webhookSecret' => Gate::allows('seller.settings.write') ? ($account->credentials['webhook_secret'] ?? null) : null,
                'webhookInstalled' => (bool) ($account->settings['webhook_installed'] ?? false),
                'linkPending' => isset($account->settings['link_code_hash'], $account->settings['link_code_expires_at']) && now()->lessThan(Carbon::parse($account->settings['link_code_expires_at'])),
            ] : null,
            'canManage' => Gate::allows('seller.settings.write'), 'status' => session('status'),
            'verification' => session('telegram_verification'), 'linkCode' => session('telegram_link_code'),
        ]);
    }

    public function verify(Request $request, TenantContext $context): RedirectResponse
    {
        $this->authorize($context, true);
        $data = $request->validate(['token' => ['nullable', 'string', 'max:255', 'regex:/^[0-9]+:[A-Za-z0-9_-]+$/D']]);
        $token = $data['token'] ?? ChannelAccount::query()->where('type', 'telegram')->first()?->credentials['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw ValidationException::withMessages(['token' => 'Введите токен бота.']);
        }

        return back()->with('telegram_verification', app(FakeTelegramClient::class)->bot($token));
    }

    public function linkCode(Request $request, TenantContext $context): RedirectResponse
    {
        $this->authorize($context, true);
        $account = ChannelAccount::query()->where('type', 'telegram')->where('status', 'active')->firstOrFail();
        $code = (string) random_int(100000, 999999);
        $settings = $account->settings ?? [];
        $settings['link_code_hash'] = hash('sha256', $code);
        $settings['link_code_expires_at'] = now()->addMinutes(10)->toISOString();
        $account->settings = $settings;
        $account->save();

        return back()->with('telegram_link_code', $code);
    }

    public function save(Request $request, TenantContext $context): RedirectResponse
    {
        $this->authorize($context, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'regex:/^[0-9]+:[A-Za-z0-9_-]+$/D'],
            'managers_chat' => ['nullable', 'string', 'regex:/^-?[0-9]{1,20}$/D'],
            'mode' => ['required', Rule::in(['bot', 'suggest', 'manager'])],
        ]);
        DB::transaction(function () use ($data, $context): void {
            $account = ChannelAccount::query()->where('type', 'telegram')->lockForUpdate()->first();
            $before = $account ? ['name' => $account->name, 'mode' => $account->default_mode, 'status' => $account->status] : null;
            if (! $account) {
                abort_unless(filled($data['token'] ?? null), 422, 'Укажите токен бота.');
                $account = new ChannelAccount;
                $account->type = 'telegram';
                $account->settings = [];
                $account->credentials = ['token' => $data['token'], 'webhook_secret' => bin2hex(random_bytes(24))];
            } elseif (filled($data['token'] ?? null)) {
                $credentials = $account->credentials ?? [];
                if (($credentials['token'] ?? null) !== $data['token']) {
                    $settings = $account->settings ?? [];
                    $settings['webhook_installed'] = false;
                    $account->settings = $settings;
                }
                $credentials['token'] = $data['token'];
                $account->credentials = $credentials;
            }
            $account->name = $data['name'];
            $account->managers_chat_id = $data['managers_chat'] ?? null;
            $account->default_mode = $data['mode'];
            $account->status = 'active';
            $account->save();
            app(AuditWriter::class)->record($context, new AuditEntry('seller.telegram.saved', 'channel_account', $account->id,
                before: $before, after: ['name' => $account->name, 'mode' => $account->default_mode, 'status' => $account->status]));
        });

        return redirect()->route('seller.channels.telegram')->with('status', 'Настройки Telegram сохранены. Настройте адрес и секрет вебхука у бота.');
    }

    public function mode(Request $request, TenantContext $context, string $channel): RedirectResponse
    {
        $this->authorize($context, true);
        $data = $request->validate(['mode' => ['required', Rule::in(['bot', 'suggest', 'manager'])]]);
        DB::transaction(function () use ($channel, $data, $context): void {
            $account = ChannelAccount::query()->findOrFail($channel);
            $before = $account->default_mode;
            $account->default_mode = $data['mode'];
            $account->save();
            app(AuditWriter::class)->record($context, new AuditEntry('seller.channel.mode.updated', 'channel_account', $account->id,
                before: ['mode' => $before], after: ['mode' => $account->default_mode]));
        });

        return back()->with('status', 'Режим канала обновлён.');
    }

    public function stop(Request $request, TenantContext $context): RedirectResponse
    {
        $this->authorize($context, true);
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($context): void {
            $count = ChannelAccount::query()->where('default_mode', 'bot')->update(['default_mode' => 'manager', 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.channels.bots.stopped', 'brand', $context->brandId,
                after: ['channel_count' => $count]));
        });

        return back()->with('status', 'Все боты остановлены.');
    }

    private function authorize(TenantContext $context, bool $write): void
    {
        Gate::authorize($write ? 'seller.settings.write' : 'seller.settings.read');
        abort_unless($context->organizationId && $context->brandId
            && app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
    }
}
