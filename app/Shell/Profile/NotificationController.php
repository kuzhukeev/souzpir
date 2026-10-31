<?php

namespace App\Shell\Profile;

use App\Models\User;
use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\EventCatalog;
use App\Shell\Notifications\RecipientPolicy;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\TestNotifications\TestNotifications;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController
{
    public function show(Request $request): Response
    {
        $settings = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context, object $brand, object $member) use ($request): array {
            $rows = DB::table('notification_settings')->where(['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $user->id])->get()->keyBy(fn ($row) => $row->event_key.':'.$row->channel);
            $events = [];
            foreach ($this->events($context) as $key => $title) {
                $channels = [];
                foreach (['cabinet', 'email', 'telegram'] as $channel) {
                    $row = $rows->get($key.':'.$channel);
                    $channels[$channel] = $row ? (bool) $row->enabled : $channel === 'cabinet';
                }
                $events[] = ['key' => $key, 'title' => $title, 'group' => str_starts_with($key, 'seller.') ? 'seller' : (str_starts_with($key, 'vision.') ? 'vision' : 'shell'), 'channels' => $channels, 'eligible' => app(RecipientPolicy::class)->permits($member, $brand->id, $key)];
            }

            return ['events' => $events, 'telegram' => app(TelegramLinks::class)->state($request, $context), 'timezone' => DB::table('profile_preferences')->where('user_id', $user->id)->value('timezone') ?? $brand->timezone, 'timingAvailable' => false, 'testAvailable' => true, 'testChannels' => app(TestNotifications::class)->availability(), 'testNotifications' => app(TestNotifications::class)->recent($context)];
        });

        return Inertia::render('Shell/Profile/Notifications', ['notificationSettings' => $settings, 'status' => session('status')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $values = ProfileValidation::validate($request, ['changes' => ['required', 'array', 'min:1', 'max:'.(count(EventCatalog::TITLES) * 3)], 'changes.*' => ['required', 'array:event,channel,enabled'], 'changes.*.event' => ['required', 'string', Rule::in(array_keys(EventCatalog::TITLES))], 'changes.*.channel' => ['required', Rule::in(['cabinet', 'email', 'telegram'])], 'changes.*.enabled' => ['required', 'boolean']]);
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($values): void {
            $available = $this->events($context);
            $seen = [];
            foreach ($values['changes'] as $change) {
                $key = $change['event'].':'.$change['channel'];
                if (! isset($available[$change['event']]) || isset($seen[$key])) {
                    throw ValidationException::withMessages(['changes' => 'Проверьте события доступных модулей.']);
                }
                $seen[$key] = true;
                if ($change['channel'] === 'telegram' && $change['enabled'] && ! DB::table('profile_telegram_links')->where(['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $user->id])->whereNotNull('chat_id')->exists()) {
                    throw ValidationException::withMessages(['changes' => 'Сначала подключите Telegram.']);
                }
                app(ConfigureNotification::class)->execute($context, $change['event'], $change['channel'], (bool) $change['enabled']);
            }
        });

        return redirect()->route('profile.notifications')->with('status', 'Настройки уведомлений сохранены.');
    }

    public function events(TenantContext $context): array
    {
        $modules = app(ModuleEntitlements::class);
        $enabled = ['seller' => $modules->enabled($context, 'seller'), 'vision' => $modules->enabled($context, 'vision')];

        return array_filter(EventCatalog::TITLES, fn ($key) => (! str_starts_with($key, 'seller.') || $enabled['seller']) && (! str_starts_with($key, 'vision.') || $enabled['vision']), ARRAY_FILTER_USE_KEY);
    }
}
