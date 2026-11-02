<?php

namespace App\Seller\Settings\Tools;

use App\Seller\Settings\Limits\SellerModuleAccess;
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
            $conversations = DB::table('conversations')->where('organization_id', $row->organization_id)->where('brand_id', $row->brand_id)
                ->where('stage', 'handoff')->whereNull('taken_at')->whereNotNull('handed_off_at')->where('handed_off_at', '<=', now()->subMinutes($minutes))->get(['id', 'handed_off_at']);
            foreach ($conversations as $conversation) {
                DB::table('seller_handoff_reminders')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $row->organization_id,
                    'brand_id' => $row->brand_id, 'conversation_id' => $conversation->id, 'handed_off_at' => $conversation->handed_off_at,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return self::SUCCESS;
    }
}
