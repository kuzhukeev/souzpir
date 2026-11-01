<?php

namespace App\Shell\Profile;

use App\Models\User;
use App\Shell\Auth\SecureAccountContext;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class EmailController
{
    public function request(Request $request): RedirectResponse
    {
        SecureAccountContext::validate($request, 'email');
        $data = ProfileValidation::validate($request, ['email' => ['required', 'string', 'email:rfc', 'max:254'], 'current_password' => ['required', 'string', 'max:1024']]);
        app(EmailChanges::class)->request($request, mb_strtolower(trim($data['email'])), $data['current_password']);

        return redirect()->route('profile.show')->with('status', 'Запрос на подтверждение нового адреса принят. Старый адрес действует до подтверждения.');
    }

    public function show(Request $request, string $change): Response
    {
        SecureAccountContext::assertRead($request);
        app(ProfileAccess::class)->run($request, fn (User $user, TenantContext $context) => app(EmailChanges::class)->preview($user, $context, $change));

        Inertia::encryptHistory();

        return app(ProfileController::class)->show($request)->with('emailConfirmationUrl', $request->getRequestUri());
    }

    public function confirm(Request $request, string $change): RedirectResponse
    {
        SecureAccountContext::assertRead($request);
        $token = $request->attributes->get('profile.email.token');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);
        app(EmailChanges::class)->confirm($request, $change, $token);
        Inertia::clearHistory();

        return redirect()->route('profile.show')->with('status', 'Новый адрес почты подтверждён. Другие сессии завершены.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $cancelled = app(EmailChanges::class)->cancel($request);
        Inertia::clearHistory();

        return redirect()->route('profile.show')->with('status', $cancelled ? 'Смена адреса отменена.' : 'Нет ожидающего запроса на смену адреса.');
    }
}
