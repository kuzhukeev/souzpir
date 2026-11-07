<?php

namespace App\Seller\Settings\Tools;

use App\Models\Membership;
use App\Seller\Runtime\Contracts\ChannelDelivery;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MonitorHandoffs extends Command
{
    protected $signature = 'seller:monitor-handoffs';

    protected $description = 'Подготовить напоминания о непринятых передачах';

    public function handle(): int
    {
        $settings = DB::table('seller_tool_preferences')->get();
        foreach ($settings as $row) {
            if (! app(SellerModuleAccess::class)->enabled($row->organization_id, $row->brand_id)) {
                continue;
            }
            $data = json_decode($row->payload, true);
            $minutes = $data['timeout_minutes'] ?? null;
            if (! is_int($minutes) || $minutes < 1 || $minutes > 10080) {
                continue;
            }
            $notify = ($data['timeout_notification'] ?? false) === true;
            $remind = ($data['timeout_reminder'] ?? false) === true;
            $fallback = is_string($data['timeout_fallback'] ?? null) ? trim($data['timeout_fallback']) : '';
            $conversations = DB::table('conversations')->where('organization_id', $row->organization_id)->where('brand_id', $row->brand_id)
                ->where('stage', 'handoff')->whereNull('taken_at')->whereNotNull('handed_off_at')->where('handed_off_at', '<=', now()->subMinutes($minutes))->get(['id', 'handed_off_at', 'version']);
            foreach ($conversations as $conversation) {
                DB::transaction(function () use ($row, $conversation, $notify, $remind, $fallback): void {
                    $stillWaiting = DB::table('conversations')->where('id', $conversation->id)->where('organization_id', $row->organization_id)
                        ->where('brand_id', $row->brand_id)->where('stage', 'handoff')->whereNull('taken_at')
                        ->where('handed_off_at', $conversation->handed_off_at)->exists();
                    if (! $stillWaiting) {
                        return;
                    }
                    $reminderId = (string) Str::ulid();
                    $inserted = DB::table('seller_handoff_reminders')->insertOrIgnore(['id' => $reminderId, 'organization_id' => $row->organization_id,
                        'brand_id' => $row->brand_id, 'conversation_id' => $conversation->id, 'handed_off_at' => $conversation->handed_off_at,
                        'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
                    if ($inserted < 1) {
                        return;
                    }
                    DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $row->organization_id,
                        'brand_id' => $row->brand_id, 'conversation_id' => $conversation->id, 'type' => 'reminder',
                        'payload' => json_encode(['text' => 'Передачу клиента менеджеру ещё не приняли.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'status' => 'new', 'priority' => 'urgent', 'created_at' => now(), 'updated_at' => now()]);
                    if ($notify) {
                        app(NotificationDispatcher::class)->enqueue(new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1,
                            'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $row->organization_id,
                            'brand_id' => $row->brand_id, 'actor_id' => null,
                            'aggregate' => ['type' => 'conversation', 'id' => $conversation->id, 'version' => max(1, (int) $conversation->version)],
                            'data' => ['reason' => 'timeout', 'task_id' => $reminderId]]));
                    }
                    if ($remind && $fallback !== '') {
                        if (! app()->bound(ChannelDelivery::class)) {
                            app()->singleton(ChannelDelivery::class, FakeChannelDelivery::class);
                        }
                        $messageId = (string) Str::ulid();
                        DB::table('messages')->insert(['id' => $messageId, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id,
                            'conversation_id' => $conversation->id, 'role' => 'agent', 'text' => $fallback,
                            'meta' => json_encode(['goal' => ['kind' => 'handoff_timeout_fallback']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                        $tenant = new TenantContext($row->organization_id, $row->brand_id, source: 'job');
                        $receipt = app(ChannelDelivery::class)->send(new DeliveryCommand($tenant, $conversation->id, $messageId, 'fake', 'handoff-timeout:'.$reminderId));
                        if ($receipt->status === 'fake_sent') {
                            DB::table('messages')->where('id', $messageId)->update(['delivered_at' => now(), 'updated_at' => now()]);
                            DB::table('conversations')->where('id', $conversation->id)->update(['last_bot_message_at' => now(), 'updated_at' => now()]);
                        }
                    }
                });
            }
        }
        $this->monitorUnassigned();

        return self::SUCCESS;
    }

    /** Schedule/Distribution "если никто не взял диалог" (notify_minutes/notify_target); a separate
     * fake artifact from the Tools/Handoff timer above, keyed on assignment instead of stage=handoff. */
    private function monitorUnassigned(): void
    {
        $schedules = DB::table('seller_schedule_preferences')->get();
        foreach ($schedules as $row) {
            if (! app(SellerModuleAccess::class)->enabled($row->organization_id, $row->brand_id)) {
                continue;
            }
            $data = json_decode($row->payload, true);
            $assignment = is_array($data['assignment'] ?? null) ? $data['assignment'] : [];
            $minutes = $assignment['notify_minutes'] ?? null;
            if (! is_int($minutes) || $minutes < 1 || $minutes > 10080) {
                continue;
            }
            $target = in_array($assignment['notify_target'] ?? null, ['all_managers', 'owner'], true) ? $assignment['notify_target'] : 'all_managers';
            $conversations = DB::table('conversations')->where('organization_id', $row->organization_id)->where('brand_id', $row->brand_id)
                ->where('mode', 'manager')->whereNull('assigned_user_id')->whereNotNull('handed_off_at')
                ->where('handed_off_at', '<=', now()->subMinutes($minutes))->get(['id', 'handed_off_at']);
            foreach ($conversations as $conversation) {
                DB::transaction(function () use ($row, $conversation, $target): void {
                    $stillWaiting = DB::table('conversations')->where('id', $conversation->id)->where('organization_id', $row->organization_id)
                        ->where('brand_id', $row->brand_id)->where('mode', 'manager')->whereNull('assigned_user_id')
                        ->where('handed_off_at', $conversation->handed_off_at)->exists();
                    if (! $stillWaiting) {
                        return;
                    }
                    $exists = DB::table('seller_tasks')->where('organization_id', $row->organization_id)->where('brand_id', $row->brand_id)
                        ->where('conversation_id', $conversation->id)->where('type', 'assignment_reminder')->exists();
                    if ($exists) {
                        return;
                    }
                    $assignee = $target === 'owner' ? $this->owner($row->organization_id, $row->brand_id) : null;
                    DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $row->organization_id,
                        'brand_id' => $row->brand_id, 'conversation_id' => $conversation->id, 'type' => 'assignment_reminder',
                        'payload' => json_encode(['text' => 'Диалог ждёт менеджера — никто не взял.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'status' => 'new', 'priority' => 'urgent', 'assigned_user_id' => $assignee, 'created_at' => now(), 'updated_at' => now()]);
                });
            }
        }
    }

    private function owner(string $organizationId, string $brandId): ?string
    {
        return Membership::withoutGlobalScopes()->where('organization_id', $organizationId)->where('role', 'owner')->where('status', 'active')->get()
            ->first(fn (Membership $member): bool => $member->permitsBrand($brandId))?->user_id;
    }
}
