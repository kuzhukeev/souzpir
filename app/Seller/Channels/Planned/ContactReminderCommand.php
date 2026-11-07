<?php

namespace App\Seller\Channels\Planned;

use App\Models\Membership;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContactReminderCommand extends Command
{
    protected $signature = 'seller:contact-reminders';

    protected $description = 'Подготовить напоминания о согласованных контактах';

    public function handle(NotificationDispatcher $notifications, ContactTime $parser): int
    {
        if (config('runtime.notification_driver') !== 'fake') {
            return self::SUCCESS;
        }
        $brands = DB::table('seller_contact_reminder_settings as settings')
            ->join('brands', function ($join): void {
                $join->on('brands.id', '=', 'settings.brand_id')->on('brands.organization_id', '=', 'settings.organization_id');
            })->join('organizations', 'organizations.id', '=', 'settings.organization_id')
            ->where('settings.enabled', true)->where('brands.status', 'active')->where('organizations.status', 'active')
            ->get(['settings.organization_id', 'settings.brand_id', 'brands.timezone']);
        foreach ($brands as $brand) {
            $conversations = DB::table('conversations')->where('organization_id', $brand->organization_id)
                ->where('brand_id', $brand->brand_id)->whereNotNull('assigned_user_id')->get(['id', 'assigned_user_id', 'state']);
            foreach ($conversations as $conversation) {
                $due = $parser->parse(data_get(json_decode($conversation->state, true), 'slots.next_contact_at'), $brand->timezone);
                $member = Membership::withoutGlobalScopes()->where(['organization_id' => $brand->organization_id,
                    'user_id' => $conversation->assigned_user_id, 'status' => 'active'])->first();
                if (! $due || $due->greaterThan(now()) || ! $member || ! $member->permitsBrand($brand->brand_id)) {
                    continue;
                }
                DB::transaction(function () use ($brand, $conversation, $due, $notifications): void {
                    $id = (string) Str::ulid();
                    $inserted = DB::table('seller_contact_reminder_events')->insertOrIgnore([
                        'id' => $id, 'organization_id' => $brand->organization_id, 'brand_id' => $brand->brand_id,
                        'conversation_id' => $conversation->id, 'user_id' => $conversation->assigned_user_id,
                        'due_at' => $due, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (! $inserted) {
                        return;
                    }
                    $notifications->enqueue(new DomainEvent(['event_id' => $id, 'schema_version' => 1,
                        'type' => 'seller.contact.reminder', 'occurred_at' => now()->toIso8601String(),
                        'organization_id' => $brand->organization_id, 'brand_id' => $brand->brand_id, 'actor_id' => null,
                        'aggregate' => ['type' => 'conversation', 'id' => $conversation->id, 'version' => 1],
                        'data' => ['reminder_id' => $id, 'user_id' => $conversation->assigned_user_id]]));
                });
            }
        }

        return self::SUCCESS;
    }
}
