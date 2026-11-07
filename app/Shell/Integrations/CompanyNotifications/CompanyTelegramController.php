<?php

namespace App\Shell\Integrations\CompanyNotifications;

use App\Models\Brand;
use App\Shell\Brands\BrandAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CompanyTelegramController
{
    private function key(TenantContext $context, Request $request): array
    {
        return ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $request->user()->id];
    }

    public function start(Request $request, TenantContext $context, BrandAccess $access): RedirectResponse
    {
        Gate::authorize('integrations.write');
        abort_unless($context->organizationId && $context->brandId, 404);
        $secret = config('features.E1-08.defaults.telegram_webhook_secret');
        $bot = config('features.E1-08.defaults.telegram_bot_username');
        if (! is_string($secret) || strlen($secret) < 32 || ! is_string($bot) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{3,28}bot$/Di', $bot)) {
            throw ValidationException::withMessages(['telegram' => 'Подключение Telegram пока не настроено.']);
        }
        $code = 'EYON-'.strtoupper(bin2hex(random_bytes(4)));
        $access->change($request, $context->brandId, function (Brand $brand) use ($context, $request, $code): void {
            Gate::authorize('integrations.write');
            DB::table('company_telegram_links')->updateOrInsert($this->key($context, $request), ['id' => (string) Str::ulid(), 'code_hash' => hash('sha256', $code), 'pending_chat' => null, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        });
        $request->session()->put('company_telegram_code', ['brand' => $context->brandId, 'code' => $code]);

        return redirect()->route('integrations.notifications');
    }

    public function receive(Request $request): Response
    {
        $expected = config('features.E1-08.defaults.telegram_webhook_secret');
        $actual = $request->header('X-Telegram-Bot-Api-Secret-Token');
        abort_unless(is_string($expected) && strlen($expected) >= 32 && is_string($actual) && hash_equals($expected, $actual), 403);
        abort_if(strlen($request->getContent()) > 16384, 413);
        $message = $request->input('message');
        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || ! is_string($message['text'] ?? null) || ! preg_match('/^\/start (EYON-[A-F0-9]{8})$/D', $message['text'], $matches)) {
            return response()->noContent();
        }
        $chat = $message['chat']['id'] ?? null;
        if ((! is_int($chat) && ! is_string($chat)) || ! preg_match('/^[1-9][0-9]{0,19}$/D', (string) $chat) || (string) $chat !== (string) ($message['from']['id'] ?? '') || ($message['from']['is_bot'] ?? true) !== false) {
            return response()->noContent();
        }
        DB::table('company_telegram_links')->where('code_hash', hash('sha256', $matches[1]))->where('expires_at', '>', now())->whereNull('pending_chat')->update(['pending_chat' => Crypt::encryptString((string) $chat), 'updated_at' => now()]);

        return response()->noContent();
    }

    public function confirm(Request $request, TenantContext $context, BrandAccess $access, CompanyNotificationSettings $settings): RedirectResponse
    {
        Gate::authorize('integrations.write');
        abort_unless($context->organizationId && $context->brandId, 404);
        $access->change($request, $context->brandId, function (Brand $brand) use ($request, $context, $settings): void {
            Gate::authorize('integrations.write');
            $link = DB::table('company_telegram_links')->where($this->key($context, $request))->where('expires_at', '>', now())->first();
            if (! $link?->pending_chat) {
                throw ValidationException::withMessages(['telegram' => 'Подтверждение ещё не получено или код истёк.']);
            }
            $current = $settings->forBrand($brand);
            $settings->save($brand, Crypt::decryptString($link->pending_chat), $current['report_emails'], $current['matrix']);
            DB::table('company_telegram_links')->where('id', $link->id)->delete();
        });
        $request->session()->forget('company_telegram_code');

        return redirect()->route('integrations.notifications')->with('success', 'Telegram подключён.');
    }
}
