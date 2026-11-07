<?php

namespace App\Seller\Channels\Widget;

use App\Models\User;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class WidgetSettingsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function settings(TenantContext $tenant): object
    {
        $query = DB::table('widget_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
        $row = $query->first();
        if ($row) {
            return $row;
        }
        $id = (string) Str::ulid();
        DB::table('widget_settings')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'public_key' => Str::random(64), 'origins' => '[]', 'appearance' => json_encode(['title' => 'Напишите нам', 'color' => '#245c41', 'position' => 'right']),
            'behavior' => json_encode(['delay_seconds' => null, 'scroll_percent' => null, 'invitation' => 'Здравствуйте! Чем помочь?']), 'created_at' => now(), 'updated_at' => now()]);

        return $query->first();
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (User $user, TenantContext $tenant) use ($request): array {
            $row = $this->settings($tenant);

            $website = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('website');
            $parts = is_string($website) ? parse_url($website) : false;
            $origin = is_array($parts) && isset($parts['scheme'], $parts['host']) && strtolower($parts['scheme']) === 'https'
                ? 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '') : null;

            return ['brandOrigin' => $origin, 'activeTab' => $request->route('tab') ?? 'install', 'settings' => ['enabled' => $row->enabled, 'origins' => json_decode($row->origins, true),
                'appearance' => json_decode($row->appearance, true), 'behavior' => json_decode($row->behavior, true),
                'last_seen_at' => $row->last_seen_at, 'key' => $row->public_key,
                'install_check_status' => $row->install_check_status, 'install_check_message' => $row->install_check_message, 'install_checked_at' => $row->install_checked_at],
                'scriptUrl' => $request->getSchemeAndHttpHost().'/widget.js',
                'contactRequests' => DB::table('widget_contact_requests')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->orderByDesc('created_at')->limit(100)->get(['id', 'name', 'email', 'phone', 'origin', 'created_at'])];
        });

        return Inertia::render('Seller/Channels/Widget/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function checkInstallation(Request $request): mixed
    {
        $result = $this->access($request, function (User $user, TenantContext $tenant): string {
            $widget = $this->settings($tenant);
            $origins = json_decode($widget->origins, true) ?: [];
            if (! $widget->enabled || $origins === []) {
                return 'Сначала включите виджет и добавьте адрес сайта.';
            }

            $passive = $widget->last_seen_at
                ? 'Виджет работает, последний сигнал: '.Carbon::parse($widget->last_seen_at)->format('d.m.Y H:i').'.'
                : 'Сигналов от виджета ещё не было.';

            if ($widget->install_checked_at && Carbon::parse($widget->install_checked_at)->gt(now()->subSeconds(30))) {
                return self::describe($widget->install_check_status, $widget->install_check_message).' '.$passive.' Активную проверку можно повторить не чаще раза в 30 секунд.';
            }

            $check = app(WidgetInstallationChecker::class)->check($origins[0], $widget->public_key);
            DB::table('widget_settings')->where('id', $widget->id)->update([
                'install_check_status' => $check['status'], 'install_check_message' => $check['message'], 'install_checked_at' => now(), 'updated_at' => now(),
            ]);

            return self::describe($check['status'], $check['message']).' '.$passive;
        });

        return back()->with('status', $result);
    }

    private static function describe(?string $status, ?string $message): string
    {
        return match ($status) {
            'detected' => 'Код установлен.',
            'not_detected' => 'Код не найден на странице.',
            'unreachable', 'failed' => $message ?: 'Проверка не удалась.',
            default => 'Проверка ещё не выполнялась.',
        };
    }

    public function update(Request $request): mixed
    {
        $input = $request->validate(['enabled' => ['required', 'boolean'], 'origins' => ['present', 'array', 'max:30'], 'origins.*' => ['required', 'string', 'max:255'],
            'appearance.title' => ['required', 'string', 'max:80'], 'appearance.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.position' => ['required', 'in:left,right'], 'behavior.delay_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'behavior.scroll_percent' => ['nullable', 'integer', 'min:1', 'max:100'], 'behavior.invitation' => ['required', 'string', 'max:200'],
            'behavior.collect_contact' => ['sometimes', 'boolean'], 'behavior.consent_url' => ['nullable', 'url:https', 'max:2048', 'required_if:behavior.collect_contact,true'],
            'behavior.exit_intent' => ['sometimes', 'boolean'], 'behavior.page_mask' => ['nullable', 'string', 'max:255', 'starts_with:/'], 'behavior.repeat_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'appearance.offset_side' => ['sometimes', 'integer', 'min:0', 'max:200'], 'appearance.offset_bottom' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'appearance.shape' => ['sometimes', 'in:round,rounded'], 'appearance.icon' => ['sometimes', 'in:chat,bot'], 'appearance.window_title' => ['sometimes', 'string', 'max:80'],
            'appearance.theme' => ['sometimes', 'in:light,dark,auto'], 'appearance.hide_mobile' => ['sometimes', 'boolean'],
            'behavior.work_days' => ['sometimes', 'array', 'max:7'], 'behavior.work_days.*' => ['integer', 'between:0,6', 'distinct'],
            'behavior.work_start' => ['nullable', 'required_with:behavior.work_days', 'date_format:H:i'], 'behavior.work_end' => ['nullable', 'required_with:behavior.work_days', 'date_format:H:i'],
            'behavior.offhours_text' => ['nullable', 'string', 'max:200'], 'behavior.excluded_paths' => ['sometimes', 'array', 'max:20'],
            'behavior.excluded_paths.*' => ['required', 'string', 'max:255', 'starts_with:/'], 'behavior.max_messages' => ['nullable', 'integer', 'min:1', 'max:100'],
            'behavior.contact_mode' => ['sometimes', 'in:none,soft,required'], 'behavior.consent_text' => ['nullable', 'string', 'max:300']]);
        $mode = $input['behavior']['contact_mode'] ?? null;
        if ($mode !== null && filter_var($input['behavior']['collect_contact'] ?? false, FILTER_VALIDATE_BOOLEAN) !== ($mode !== 'none')) {
            throw ValidationException::withMessages(['behavior.contact_mode' => 'Режим сбора контакта не соответствует настройке согласия.']);
        }
        $origins = [];
        foreach ($input['origins'] as $origin) {
            $value = strtolower(rtrim(trim($origin), '/'));
            $parts = parse_url($value);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
                || ! in_array($parts['scheme'], ['https', 'http'], true) || ($parts['scheme'] === 'http' && ! in_array($parts['host'], ['localhost', '127.0.0.1'], true))) {
                throw ValidationException::withMessages(['origins' => 'Укажите адреса сайтов целиком, например https://example.ru. HTTP разрешён только для localhost.']);
            }
            $origins[] = $value;
        }
        if ($input['enabled'] && ! $origins) {
            throw ValidationException::withMessages(['origins' => 'Добавьте хотя бы один сайт.']);
        }
        $this->access($request, function (User $user, TenantContext $tenant) use ($input, $origins): void {
            $row = $this->settings($tenant);
            DB::table('widget_settings')->where('id', $row->id)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->update(['enabled' => $input['enabled'], 'origins' => json_encode(array_values(array_unique($origins))),
                    'appearance' => json_encode($input['appearance'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'behavior' => json_encode($input['behavior'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $channel = ChannelAccount::query()->where('type', 'widget')->where('settings->widget_id', $row->id)->first();
            if (! $channel) {
                $channel = new ChannelAccount;
                $channel->type = 'widget';
                $channel->name = 'Сайт';
                $channel->default_mode = 'manager';
                $channel->settings = ['widget_id' => $row->id];
            }
            if ($input['enabled'] && $channel->status !== 'active' && $channel->connected_at === null) {
                $channel->connected_at = now();
            }
            $channel->status = $input['enabled'] ? 'active' : 'disabled';
            $channel->save();
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.widget.settings.updated', 'widget_settings', $row->id));
        });

        return back()->with('status', 'Настройки виджета сохранены.');
    }
}
