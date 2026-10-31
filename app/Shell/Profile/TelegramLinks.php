<?php

namespace App\Shell\Profile;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\SecureAccountContext;
use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\EventCatalog;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TelegramLinks
{
    public function configured(): bool
    {
        $name = config('features.E1-08.defaults.telegram_bot_username');
        $secret = config('features.E1-08.defaults.telegram_webhook_secret');

        return is_string($name) && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{3,28}bot$/Di', $name) && is_string($secret) && strlen($secret) >= 32;
    }

    private function where(TenantContext $context): array
    {
        return ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $context->actorId];
    }

    public function state(Request $request, TenantContext $context): array
    {
        $row = DB::table('profile_telegram_links')->where($this->where($context))->first();
        $pending = $row?->pending_chat_id && $row->expires_at && now()->lessThan($row->expires_at);
        $session = $request->session()->get('profile.telegram_link');
        $link = $row?->token_hash && $row->expires_at && now()->lessThan($row->expires_at) && is_array($session) && ($session['id'] ?? null) === $row->id && is_string($session['token_hash'] ?? null) && hash_equals($row->token_hash, $session['token_hash']) ? ($session['url'] ?? null) : null;
        if (! $link) {
            $request->session()->forget('profile.telegram_link');
        }

        return ['configured' => $this->configured(), 'connected' => $row !== null && $row->chat_id !== null, 'link_available' => (bool) $link && SecureAccountContext::allows($request), 'expires_at' => ($link || $pending) ? $row->expires_at : null, 'pending' => (bool) $pending, 'pending_label' => $pending ? $row->pending_label : null];
    }

    public function start(Request $request): void
    {
        SecureAccountContext::validate($request, 'telegram');
        $link = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context): array {
            if (! $this->configured()) {
                throw ValidationException::withMessages(['telegram' => 'Подключение Telegram пока не настроено.']);
            }
            $row = DB::table('profile_telegram_links')->where($this->where($context))->lockForUpdate()->first();
            if ($row?->chat_id) {
                throw ValidationException::withMessages(['telegram' => 'Сначала отключите текущее подключение.']);
            }
            $id = $row?->id ?? (string) Str::ulid();
            $token = bin2hex(random_bytes(24));
            DB::table('profile_telegram_links')->updateOrInsert($this->where($context), ['id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10), 'pending_chat_id' => null, 'pending_label' => null, 'chat_id' => null, 'chat_fingerprint' => null, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('profile.telegram.requested', 'user', $user->id));

            return ['id' => $id, 'token_hash' => hash('sha256', $token), 'url' => 'https://t.me/'.config('features.E1-08.defaults.telegram_bot_username').'?start='.$token];
        });
        $request->session()->put('profile.telegram_link', $link);
    }

    /** Return a capability only via a separate no-store redirect, never an Inertia prop. */
    public function link(Request $request): string
    {
        SecureAccountContext::assertRead($request);

        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($request): string {
            $row = DB::table('profile_telegram_links')->where($this->where($context))->lockForUpdate()->first();
            $session = $request->session()->get('profile.telegram_link');
            abort_unless($row && $row->token_hash && $row->expires_at && now()->lessThan($row->expires_at)
                && is_array($session) && ($session['id'] ?? null) === $row->id
                && is_string($session['token_hash'] ?? null) && hash_equals($row->token_hash, $session['token_hash'])
                && is_string($session['url'] ?? null), 404);

            return $session['url'];
        });
    }

    /** Secret checked before any body/token lookup; only private chat /start is accepted. */
    public function receive(Request $request): void
    {
        $expected = config('features.E1-08.defaults.telegram_webhook_secret');
        $actual = $request->header('X-Telegram-Bot-Api-Secret-Token');
        abort_unless($this->configured() && is_string($actual) && hash_equals($expected, $actual), 403);
        abort_if(strlen($request->getContent()) > 16384, 413);
        $message = $request->input('message');
        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || ! is_string($message['text'] ?? null) || ! preg_match('/^\/start ([a-f0-9]{48})$/D', $message['text'], $match)) {
            return;
        }
        $chat = $message['chat']['id'] ?? null;
        $from = $message['from']['id'] ?? null;
        if ((! is_int($chat) && ! is_string($chat)) || ! preg_match('/^[1-9][0-9]{0,19}$/D', (string) $chat) || (string) $chat !== (string) $from || ($message['from']['is_bot'] ?? true) !== false) {
            return;
        }
        $hash = hash('sha256', $match[1]);
        $snapshot = DB::table('profile_telegram_links')->where('token_hash', $hash)->first();
        if (! $snapshot) {
            return;
        }
        DB::transaction(function () use ($snapshot, $hash, $chat, $message): void {
            $user = User::whereKey($snapshot->user_id)->lock('for no key update')->first();
            $org = DB::table('organizations')->where('id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $brand = DB::table('brands')->where('id', $snapshot->brand_id)->where('organization_id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $member = Membership::withoutGlobalScopes()->where('organization_id', $snapshot->organization_id)->where('user_id', $snapshot->user_id)->where('status', 'active')->lockForUpdate()->first();
            $row = DB::table('profile_telegram_links')->where('id', $snapshot->id)->where('token_hash', $hash)->lockForUpdate()->first();
            if (! $user || ! $org || ! $brand || ! $member || ! $member->permitsBrand($brand->id) || ! $row || $row->chat_id || ! $row->expires_at || now()->greaterThanOrEqualTo($row->expires_at)) {
                return;
            }
            $label = is_string($message['from']['username'] ?? null) ? '@'.$message['from']['username'] : 'Личный чат •••'.substr((string) $chat, -4);
            DB::table('profile_telegram_links')->where('id', $row->id)->update(['token_hash' => null, 'pending_chat_id' => Crypt::encryptString((string) $chat), 'pending_label' => mb_substr($label, 0, 120), 'updated_at' => now()]);
            app(AuditWriter::class)->record(new TenantContext($org->id, $brand->id, $user->id, $member->id), new AuditEntry('profile.telegram.awaiting_confirmation', 'user', $user->id));
        });
    }

    public function confirm(Request $request): void
    {
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context, object $brand, Membership $member): void {
            $row = DB::table('profile_telegram_links')->where($this->where($context))->lockForUpdate()->first();
            if (! $row || $row->chat_id || ! $row->pending_chat_id || ! $row->expires_at || now()->greaterThanOrEqualTo($row->expires_at)) {
                throw ValidationException::withMessages(['telegram' => 'Подключение истекло. Начните заново.']);
            }
            $chat = Crypt::decryptString($row->pending_chat_id);
            $fingerprint = hash_hmac('sha256', 'profile-chat:'.$chat, (string) config('app.key'));
            if (DB::table('profile_telegram_accounts')->where('chat_fingerprint', $fingerprint)->where('user_id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['telegram' => 'Этот чат уже подключён.']);
            }
            $account = DB::table('profile_telegram_accounts')->where('user_id', $user->id)->lockForUpdate()->first();
            if ($account && ! hash_equals($account->chat_fingerprint, $fingerprint)) {
                $this->removeInaccessibleBindings($user, $context, $member);
                if (DB::table('profile_telegram_links')->where('user_id', $user->id)->whereNotNull('chat_id')->exists()) {
                    throw ValidationException::withMessages(['telegram' => 'Сначала отключите прежний Telegram в доступных брендах.']);
                }
                DB::table('profile_telegram_accounts')->where('user_id', $user->id)->delete();
            }
            DB::table('profile_telegram_accounts')->insertOrIgnore(['user_id' => $user->id, 'chat_fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
            $account = DB::table('profile_telegram_accounts')->where('user_id', $user->id)->first();
            if (! $account || ! hash_equals($account->chat_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['telegram' => 'Этот чат уже подключён.']);
            }
            foreach (EventCatalog::TITLES as $key => $title) {
                $enabled = DB::table('notification_settings')->where([...$this->where($context), 'event_key' => $key, 'channel' => 'telegram'])->value('enabled') ?? false;
                app(ConfigureNotification::class)->execute($context, $key, 'telegram', (bool) $enabled, $chat);
            }
            DB::table('profile_telegram_links')->where('id', $row->id)->update(['chat_id' => $row->pending_chat_id, 'chat_fingerprint' => $fingerprint, 'pending_chat_id' => null, 'pending_label' => null, 'token_hash' => null, 'expires_at' => null, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('profile.telegram.connected', 'user', $user->id));
        });
        $request->session()->forget('profile.telegram_link');
    }

    public function disconnect(Request $request): void
    {
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context): void {
            DB::table('profile_telegram_links')->where($this->where($context))->delete();
            if (! DB::table('profile_telegram_links')->where('user_id', $user->id)->whereNotNull('chat_id')->exists()) {
                DB::table('profile_telegram_accounts')->where('user_id', $user->id)->delete();
            }
            DB::table('notification_settings')->where([...$this->where($context), 'channel' => 'telegram'])->update(['enabled' => false, 'destination' => null, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('profile.telegram.disconnected', 'user', $user->id));
        });
        $request->session()->forget('profile.telegram_link');
    }

    /** Explicitly confirming a new chat may retire only this user's inaccessible bindings in this organization. */
    private function removeInaccessibleBindings(User $user, TenantContext $context, Membership $member): void
    {
        $links = DB::table('profile_telegram_links')->where('organization_id', $context->organizationId)->where('user_id', $user->id)->whereNotNull('chat_id')->orderBy('brand_id')->lockForUpdate()->get();
        foreach ($links as $link) {
            $brand = DB::table('brands')->where('id', $link->brand_id)->where('organization_id', $context->organizationId)->lockForUpdate()->first();
            if ($brand && $brand->status === 'active' && $member->permitsBrand($brand->id)) {
                continue;
            }
            DB::table('notification_settings')->where(['organization_id' => $context->organizationId, 'brand_id' => $link->brand_id, 'user_id' => $user->id, 'channel' => 'telegram'])->update(['enabled' => false, 'destination' => null, 'updated_at' => now()]);
            DB::table('profile_telegram_links')->where('id', $link->id)->where('organization_id', $context->organizationId)->where('user_id', $user->id)->delete();
            app(AuditWriter::class)->record($context, new AuditEntry('profile.telegram.inaccessible_binding_removed', 'user', $user->id, after: ['removed_brand_id' => $link->brand_id]));
        }
    }
}
