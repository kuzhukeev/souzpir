<?php

namespace App\Shell\Profile;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TelegramController
{
    public function start(Request $request): RedirectResponse
    {
        app(TelegramLinks::class)->start($request);

        return redirect()->route('profile.notifications')->with('status', 'Откройте ссылку Telegram, нажмите «Старт», затем вернитесь и подтвердите подключение.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        app(TelegramLinks::class)->confirm($request);

        return redirect()->route('profile.notifications')->with('status', 'Telegram подключён. Выберите события для отправки.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        app(TelegramLinks::class)->disconnect($request);

        return redirect()->route('profile.notifications')->with('status', 'Telegram отключён.');
    }

    public function webhook(Request $request): Response
    {
        app(TelegramLinks::class)->receive($request);

        return response()->noContent();
    }
}
