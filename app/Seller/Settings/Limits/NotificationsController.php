<?php

namespace App\Seller\Settings\Limits;

use App\Models\Membership;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\EventCatalog;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationsController
{
    private const CHANNELS = ['cabinet', 'email', 'telegram'];

    public function index(TenantContext $context): Response
    {
        Gate::authorize('seller.settings.read');
        $this->member($context);
        $settings = DB::table('seller_notification_rules')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->get()->keyBy('event_key');
        $rows = [];
        foreach (SellerNotificationRouter::EVENTS as $event) {
            $rule = $settings->get($event);
            $rows[] = [
                'event' => $event, 'label' => EventCatalog::TITLES[$event],
                'recipient' => $rule?->recipient ?? 'all',
                'roles' => self::roles($event),
                'channels' => ['cabinet' => $rule ? (bool) $rule->cabinet : true, 'email' => $rule ? (bool) $rule->email : true, 'telegram' => $rule ? (bool) $rule->telegram : true],
                'timing' => $rule?->timing ?? 'immediate',
            ];
        }

        return Inertia::render('Seller/Settings/Limits/Notifications', [
            'rows' => $rows, 'canEdit' => Gate::allows('seller.settings.write'),
            'digestAvailable' => (bool) config('features.E6-07.defaults.digest_enabled', false),
            'testKey' => (string) Str::uuid(),
            'tests' => DB::table('seller_test_deliveries')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('user_id', $context->actorId)
                ->orderByDesc('created_at')->limit(10)->get(['id', 'event_key', 'channel', 'status', 'created_at']),
        ]);
    }

    public function update(Request $request, TenantContext $context): RedirectResponse
    {
        Gate::authorize('seller.settings.write');
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:7'],
            'changes.*.event' => ['required', Rule::in(SellerNotificationRouter::EVENTS)],
            'changes.*.recipient' => ['required', Rule::in(['all', 'owner', 'admin', 'manager', 'editor'])],
            'changes.*.channels' => ['required', 'array:cabinet,email,telegram'],
            'changes.*.channels.cabinet' => ['required', 'boolean'],
            'changes.*.channels.email' => ['required', 'boolean'],
            'changes.*.channels.telegram' => ['required', 'boolean'],
            'changes.*.timing' => ['required', Rule::in(['immediate', 'digest'])],
        ]);
        $changes = $data['changes'];
        usort($changes, fn ($a, $b) => $a['event'] <=> $b['event']);
        $seen = [];
        foreach ($changes as $change) {
            if (isset($seen[$change['event']]) || ! in_array($change['recipient'], self::roles($change['event']), true)) {
                throw ValidationException::withMessages(['changes' => 'Получатель недоступен для события или событие указано дважды.']);
            }
            if ($change['timing'] === 'digest' && ! config('features.E6-07.defaults.digest_enabled', false)) {
                throw ValidationException::withMessages(['changes' => 'Отправка сводки пока недоступна.']);
            }
            $seen[$change['event']] = true;
        }
        DB::transaction(function () use ($context, $changes): void {
            DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = $this->member($context, true);
            abort_unless(in_array($member->role, ['owner', 'admin'], true), 403);
            foreach ($changes as $change) {
                $where = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'event_key' => $change['event']];
                $old = DB::table('seller_notification_rules')->where($where)->lockForUpdate()->first();
                $new = ['recipient' => $change['recipient'], ...$change['channels'], 'timing' => $change['timing']];
                DB::table('seller_notification_rules')->updateOrInsert($where, [...$new, 'id' => $old?->id ?? (string) Str::ulid(), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
                app(AuditWriter::class)->record($context, new AuditEntry('seller.notification_rule.updated', 'seller_notification_rule', $change['event'], before: $old ? ['recipient' => $old->recipient, 'timing' => $old->timing] : null, after: $new));
            }
        });

        return redirect()->route('seller.settings.notifications')->with('status', 'Настройки сохранены.');
    }

    private static function roles(string $event): array
    {
        return $event === 'seller.conversation.handed_off' ? ['all', 'owner', 'admin', 'manager', 'editor'] : ['all', 'owner', 'admin'];
    }

    private function member(TenantContext $context, bool $lock = false): Membership
    {
        $query = Membership::withoutGlobalScopes()->where('organization_id', $context->organizationId)
            ->where('id', $context->membershipId)->where('user_id', $context->actorId)->where('status', 'active');
        $member = ($lock ? $query->lockForUpdate() : $query)->first() ?? abort(403);
        if (! $context->brandId || ! $member->permitsBrand($context->brandId)) {
            abort(403);
        }

        return $member;
    }
}
