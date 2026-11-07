<?php

namespace App\Shell\Profile\Security;

use App\Shell\Profile\LocalProfileTransport;
use App\Shell\Profile\ProfileValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityController
{
    public function show(#[\SensitiveParameter] Request $request, SecuritySettings $settings): Response
    {
        if (LocalProfileTransport::allows($request)) {
            return LocalProfileTransport::shell('security');
        }
        Inertia::encryptHistory();

        return Inertia::render('Shell/Profile/Security', ['security' => $settings->view($request), 'status' => $request->session()->get('status')]);
    }

    public function password(#[\SensitiveParameter] Request $request, SecuritySettings $settings): RedirectResponse
    {
        $data = ProfileValidation::validate($request, [
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'confirmed', 'max:1024', Password::min(12)],
        ]);
        $settings->password($request, $data);

        return redirect()->route('profile.security')->with('status', 'Пароль изменён. Остальные устройства вышли из аккаунта.');
    }

    public function revoke(#[\SensitiveParameter] Request $request, string $id, SecuritySettings $settings): RedirectResponse
    {
        $settings->revoke($request, $id);

        return redirect()->route('profile.security')->with('status', 'Сессия завершена.');
    }

    public function revokeOthers(#[\SensitiveParameter] Request $request, SecuritySettings $settings): RedirectResponse
    {
        $settings->revokeOthers($request);

        return redirect()->route('profile.security')->with('status', 'Все остальные сессии завершены.');
    }
}
