<?php

namespace App\Shell\Profile\TestNotifications;

use App\Shell\Profile\ProfileValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TestNotificationController
{
    public function __invoke(#[\SensitiveParameter] Request $request, TestNotifications $notifications): RedirectResponse
    {
        $data = ProfileValidation::validate($request, ['channel' => ['required', Rule::in(['cabinet', 'email', 'telegram'])], 'request_key' => ['required', 'uuid']]);
        $id = $notifications->request($request, $data['channel'], $data['request_key']);
        if ($data['channel'] !== 'cabinet') {
            DeliverTestNotification::dispatch($id)->afterCommit();
        }

        return redirect()->route('profile.notifications')->with('status', $data['channel'] === 'cabinet' ? 'Тестовое уведомление получено. Оно показано в истории тестов.' : 'Тестовое уведомление поставлено в очередь.');
    }
}
