<?php

namespace App\Seller\Settings\Policy;

use App\Models\User;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Settings\BrandSellerSettings;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class PolicySettingsController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback, $permission) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'seller.knowledge.read', function (TenantContext $tenant): array {
            $row = BrandSellerSettings::query()->first();
            $followup = array_replace(['enabled' => true, 'first_hours' => 20, 'second_hours' => null, 'task_after_hours' => 24, 'max_auto' => 2, 'window' => null, 'texts' => [], 'agreed_text' => '', 'excluded_stages' => []], $row?->followup ?? []);
            $reactionExamples = [];
            $reactionActions = [];
            try {
                $snapshot = app(KnowledgeRepository::class)->published($tenant);
                $policy = $snapshot->policy();
                foreach (['decline_off_topic', 'decline_off_topic_second', 'decline_injection', 'opt_out_ack'] as $tag) {
                    $reactionExamples[$tag] = array_values(array_filter(array_map(
                        static fn (array $item): ?string => is_string($item['text'] ?? null) ? $item['text'] : null,
                        $snapshot->fewshot([$tag], null, 6, 0.7)
                    )));
                }
                $reason = collect($snapshot->escalation()['reasons'] ?? [])->firstWhere('id', 'negative_sentiment');
                $reactionExamples['negative_sentiment'] = is_string($reason['message_idea'] ?? null) ? [$reason['message_idea']] : [];
                $reactionActions = ['decline_off_topic' => ($policy['off_topic_action'] ?? 'spam') === 'handoff' ? 'Передать менеджеру' : 'Завершить как спам',
                    'decline_off_topic_second' => ($policy['off_topic_action'] ?? 'spam') === 'handoff' ? 'Передать менеджеру' : 'Завершить как спам',
                    'negative_sentiment' => ! $reason ? 'Причина передачи не настроена' : (($reason['mode'] ?? 'handoff') === 'notify' ? 'Уведомить менеджера' : 'Передать менеджеру'),
                    'decline_injection' => 'Отклонить запрос', 'opt_out_ack' => 'Не беспокоить'];
            } catch (KnowledgeNotReady) {
                $policy = null;
            }

            $draftPolicy = null;
            $draftReactions = null;
            if (Gate::allows('seller.settings.write')) {
                $draft = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->first(['id', 'version']);
                if ($draft) {
                    $draftSnapshot = app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true);
                    $draftPolicy = ['version' => $draft->version, 'policy' => $draftSnapshot->policy()];
                    $draftReactions = [];
                    foreach (['decline_off_topic', 'decline_off_topic_second', 'decline_injection', 'opt_out_ack'] as $tag) {
                        $draftReactions[$tag] = array_values(array_map(static fn (array $item): string => (string) ($item['text'] ?? ''), array_filter($draftSnapshot->all('fewshot')['examples'] ?? [], static fn ($item): bool => is_array($item) && ($item['tag'] ?? null) === $tag)));
                    }
                    $reason = collect($draftSnapshot->escalation()['reasons'] ?? [])->firstWhere('id', 'negative_sentiment');
                    $draftReactions['negative_sentiment'] = is_string($reason['message_idea'] ?? null) ? [$reason['message_idea']] : [];
                }
            }

            $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone');
            $events = DB::table('messages')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where(fn ($query) => $query->where('role', 'client')->orWhere(fn ($query) => $query->where('role', 'agent')->where('meta->goal->kind', 'followup_nudge')))
                ->orderBy('conversation_id')->orderBy('created_at')->orderBy('id')->get(['conversation_id', 'role']);
            $sent = 0;
            $replies = [1 => 0, 2 => 0];
            $conversation = null;
            $sequence = 0;
            foreach ($events as $event) {
                if ($conversation !== $event->conversation_id) {
                    $conversation = $event->conversation_id;
                    $sequence = 0;
                }
                if ($event->role === 'agent') {
                    $sent++;
                    $sequence++;
                } else {
                    if (isset($replies[$sequence])) {
                        $replies[$sequence]++;
                    }
                    $sequence = 0;
                }
            }

            return ['followup' => $followup, 'policy' => $policy, 'reactionExamples' => $reactionExamples, 'reactionActions' => $reactionActions, 'draftReactions' => $draftReactions, 'canWrite' => Gate::allows('seller.settings.write'), 'draftPolicy' => $draftPolicy,
                'followupTimezone' => $timezone, 'followupSent' => $sent, 'followupReplies' => array_values($replies)];
        });

        return Inertia::render('Seller/Settings/Policy/Index', [...$data, 'status' => session('status')]);
    }

    public function fields(Request $request): mixed
    {
        $data = $this->access($request, 'seller.knowledge.read', function (TenantContext $tenant): array {
            $draft = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->first(['id', 'version']);
            $draftFields = $draft ? (app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->policy()['client_fields'] ?? []) : [];
            $editor = ['version' => $draft?->version, 'fields' => is_array($draftFields) ? $draftFields : []];
            try {
                $snapshot = app(KnowledgeRepository::class)->published($tenant);
                $policy = $snapshot->policy();
                $orders = $policy['slot_order'] ?? [];
                $unknownOrder = $orders['unknown'] ?? [];
                $rows = [];
                $initialSlots = (new LeadState)->data['slots'];
                foreach (LeadState::SLOTS as $slot) {
                    $tag = $policy['missing_question_tags'][$slot] ?? 'ask_'.$slot;
                    $example = collect($snapshot->fewshot([$tag], null, 6, 0.7))
                        ->first(fn ($item) => ($item['client_type'] ?? 'all') === 'all')['text'] ?? null;
                    $position = array_search($slot, $unknownOrder, true);
                    $absent = [];
                    foreach ($orders as $type => $order) {
                        if (is_array($order) && ! in_array($slot, $order, true)) {
                            $absent[] = $type;
                        }
                    }
                    $rows[] = ['id' => $slot, 'example' => $example, 'priority' => $position === false ? null : $position + 1,
                        'absent_from_order' => $absent, 'condition' => $policy['conditional_slots'][$slot] ?? null,
                        'value_kind' => is_array($initialSlots[$slot] ?? null) ? 'list' : 'text'];
                }

                return ['available' => true, 'fields' => $rows, 'editor' => $editor, 'canRequest' => Gate::allows('seller.settings.write')];
            } catch (KnowledgeNotReady) {
                return ['available' => false, 'fields' => [], 'editor' => $editor, 'canRequest' => Gate::allows('seller.settings.write')];
            }
        });

        return Inertia::render('Seller/Settings/Policy/Fields', [...$data, 'requestKey' => (string) Str::ulid()]);
    }

    public function update(Request $request): mixed
    {
        $input = $request->validate(['enabled' => ['required', 'boolean'], 'first_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'second_hours' => ['nullable', 'integer', 'min:1', 'max:720'], 'task_after_hours' => ['sometimes', 'integer', 'min:1', 'max:720'], 'max_auto' => ['required', 'integer', 'min:0', 'max:10'],
            'window' => ['nullable', 'array'], 'window.start' => ['required_with:window', 'date_format:H:i'], 'window.end' => ['required_with:window', 'date_format:H:i'],
            'excluded_stages' => ['sometimes', 'array', 'max:6'], 'excluded_stages.*' => ['string', 'distinct', Rule::in(['open', 'qualify', 'need', 'budget', 'close_contact', 'followup'])],
            'texts' => ['present', 'array', 'max:5'], 'texts.*' => ['nullable', 'string', 'max:500'], 'agreed_text' => ['nullable', 'string', 'max:500']]);
        if (($input['window']['start'] ?? null) !== null && $input['window']['start'] === $input['window']['end']) {
            throw ValidationException::withMessages(['window.end' => 'Конец окна должен отличаться от начала.']);
        }
        $this->access($request, 'seller.settings.write', function (TenantContext $tenant) use ($input): void {
            $row = BrandSellerSettings::query()->first() ?? new BrandSellerSettings;
            $row->followup = ['enabled' => (bool) $input['enabled'], 'first_hours' => (int) $input['first_hours'],
                'second_hours' => isset($input['second_hours']) ? (int) $input['second_hours'] : null,
                'task_after_hours' => (int) ($input['task_after_hours'] ?? $row->followup['task_after_hours'] ?? 24),
                'max_auto' => (int) $input['max_auto'], 'window' => $input['window'] ?? null,
                'texts' => array_values(array_filter($input['texts'], fn ($text) => is_string($text) && trim($text) !== '')),
                'agreed_text' => $input['agreed_text'] ?? '', 'excluded_stages' => $input['excluded_stages'] ?? ($row->followup['excluded_stages'] ?? [])];
            $row->save();
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.followup.settings.updated', 'brand_seller_settings', $row->id));
        });

        return back()->with('status', 'Настройки напоминаний сохранены.');
    }
}
