<?php

namespace App\Seller\Followups;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PrepareFollowups implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $organizationId, public string $brandId) {}

    public function handle(FollowupPolicy $policy): void
    {
        $brand = DB::table('brands')->where('id', $this->brandId)->where('organization_id', $this->organizationId)->where('status', 'active')->first(['id', 'timezone']);
        if (! $brand || ! app(SellerModuleAccess::class)->enabled($this->organizationId, $this->brandId)) {
            return;
        }
        $settingsRow = DB::table('brand_seller_settings')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)->first(['followup']);
        $settings = $settingsRow ? json_decode($settingsRow->followup, true) : [];
        $settings = is_array($settings) ? $settings : [];
        if (($settings['enabled'] ?? true) === false) {
            return;
        }
        $texts = array_values(array_filter($settings['texts'] ?? [], fn ($value) => is_string($value) && trim($value) !== ''));
        if (! $texts) {
            try {
                $knowledge = app(KnowledgeRepository::class)->published(new TenantContext($this->organizationId, $this->brandId, source: 'job'));
                $texts = collect(data_get($knowledge->all('fewshot'), 'examples', []))->where('tag', 'followup_nudge')->pluck('text')->filter(fn ($value) => is_string($value) && trim($value) !== '')->values()->all();
            } catch (\Throwable) {
                // The prototype has a fallback if there are no suitable examples.
            }
        }
        if (! $texts) {
            $texts = ['Добрый день. Это Антон из «Союз Пир», на связи по вашей заявке. Если актуально — продолжим, если нет — просто напишите, я не буду беспокоить.'];
        }
        $now = CarbonImmutable::now();
        $conversations = DB::table('conversations')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)->whereNotNull('last_client_message_at')->get();
        foreach ($conversations as $conversation) {
            $last = DB::table('messages')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)
                ->where('conversation_id', $conversation->id)->where('role', 'agent')->orderByDesc('created_at')->orderByDesc('id')->value('created_at');
            $state = json_decode($conversation->state, true);
            $data = ['stage' => $conversation->stage, 'state' => is_array($state) ? $state : [], 'do_not_disturb' => $conversation->do_not_disturb,
                'followups_sent' => $conversation->followups_sent, 'last_client_message_at' => $conversation->last_client_message_at];
            $due = $policy->due($data, $settings, $last ? CarbonImmutable::parse($last) : null, $now, $brand->timezone);
            if (! $due) {
                continue;
            }
            $text = $due['reason'] === 'next_contact_at'
                ? (is_string($settings['agreed_text'] ?? null) && trim($settings['agreed_text']) !== '' ? $settings['agreed_text'] : 'Добрый день. Это Антон из «Союз Пир», договаривались списаться. Успели посмотреть прайс? Если есть вопросы по позициям или условиям — отвечу.')
                : $texts[($due['sequence'] - 1) % count($texts)];
            DB::table('seller_followup_candidates')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $this->organizationId,
                'brand_id' => $this->brandId, 'conversation_id' => $conversation->id, 'reason' => $due['reason'], 'source_at' => $due['source_at'],
                'sequence' => $due['sequence'], 'text' => $text, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
