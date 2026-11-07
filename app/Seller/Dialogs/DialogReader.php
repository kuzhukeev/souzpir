<?php

namespace App\Seller\Dialogs;

use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DialogReader
{
    private const SLOTS = [
        'name' => 'Имя', 'city' => 'Город', 'purpose' => 'Цель', 'experience' => 'Опыт в нише',
        'outlet_type' => 'Тип точки', 'outlet_floor' => 'Этаж / помещение', 'categories' => 'Интересующие категории',
        'brands_interest' => 'Интересующие бренды', 'current_supplier' => 'Текущий поставщик',
        'competitor_discount' => 'Скидка у конкурента', 'budget_range' => 'Бюджет', 'timing' => 'Сроки',
        'legal' => 'Юридический статус', 'email' => 'Почта', 'messenger' => 'Мессенджер / телефон',
        'next_contact_at' => 'Когда связаться',
    ];

    public function read(TenantContext $context, string $actorId, bool $manager, bool $showMeta, ?string $selected, string $mode, string $search, string $sort = 'newest', array $filters = [], array $publishedFields = []): array
    {
        $base = DB::table('conversations as c')->leftJoin('contacts as contact', function ($join): void {
            $join->on('contact.id', '=', 'c.contact_id')->on('contact.brand_id', '=', 'c.brand_id')
                ->on('contact.organization_id', '=', 'c.organization_id');
        })->leftJoin('channel_accounts as channel', function ($join): void {
            $join->on('channel.id', '=', 'c.channel_account_id')->on('channel.brand_id', '=', 'c.brand_id')
                ->on('channel.organization_id', '=', 'c.organization_id');
        })->where('c.organization_id', $context->organizationId)->where('c.brand_id', $context->brandId);
        if ($manager) {
            $base->where(fn ($query) => $query->where('c.assigned_user_id', $actorId)
                ->orWhere(fn ($waiting) => $waiting->where('c.mode', 'manager')->whereNull('c.assigned_user_id')));
        }
        $managerOptions = (clone $base)->join('users as assigned', 'assigned.id', '=', 'c.assigned_user_id')
            ->select('assigned.id', 'assigned.name')->distinct()->orderBy('assigned.name')->orderBy('assigned.id')->get();
        $mine = (clone $base)->where(fn ($query) => $query->where('c.assigned_user_id', $actorId)
            ->orWhere(fn ($waiting) => $waiting->where('c.mode', 'manager')->whereNull('c.assigned_user_id')));
        $counterRows = $mine->select(['c.stage', 'c.updated_at'])->selectSub(
            DB::table('messages as m')->select('m.role')->whereColumn('m.conversation_id', 'c.id')
                ->whereColumn('m.organization_id', 'c.organization_id')->whereColumn('m.brand_id', 'c.brand_id')
                ->orderByDesc('m.created_at')->orderByDesc('m.id')->limit(1), 'last_role'
        )->get();
        $closed = ['done', 'lost', 'spam'];
        $counts = [
            'waiting' => $counterRows->filter(fn ($row) => ! in_array($row->stage, $closed, true) && $row->last_role === 'client')->count(),
            'active' => $counterRows->filter(fn ($row) => ! in_array($row->stage, $closed, true))->count(),
            'closed_today' => $counterRows->filter(fn ($row) => in_array($row->stage, $closed, true) && Carbon::parse($row->updated_at)->isToday())->count(),
        ];
        if ($mode === 'mine') {
            $base->where(fn ($query) => $query->where('c.assigned_user_id', $actorId)
                ->orWhere(fn ($waiting) => $waiting->where('c.mode', 'manager')->whereNull('c.assigned_user_id')));
        } elseif ($mode === 'waiting') {
            $base->where('c.mode', 'manager')->whereNull('c.assigned_user_id')->whereNotIn('c.stage', $closed);
        } elseif ($mode === 'closed') {
            $base->whereIn('c.stage', $closed);
        } elseif ($mode !== 'all') {
            $base->where('c.mode', $mode);
        }
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '%_\\').'%';
            $base->where(fn ($query) => $query->where('contact.name', 'ilike', $pattern)
                ->orWhere('contact.email', 'ilike', $pattern)->orWhere('contact.phone', 'ilike', $pattern)
                ->orWhere('c.id', $search)->orWhereExists(fn ($messages) => $messages->selectRaw('1')->from('messages as search_message')
                ->whereColumn('search_message.conversation_id', 'c.id')->whereColumn('search_message.brand_id', 'c.brand_id')
                ->whereColumn('search_message.organization_id', 'c.organization_id')->where('search_message.text', 'ilike', $pattern)));
        }
        if (! empty($filters['channel'])) {
            $base->where('channel.type', $filters['channel']);
        }
        if (! empty($filters['stage'])) {
            $base->where('c.stage', $filters['stage']);
        }
        if (! empty($filters['manager'])) {
            $base->where('c.assigned_user_id', $filters['manager']);
        }
        if (! empty($filters['outcome'])) {
            $base->where('c.outcome', $filters['outcome']);
        }
        if (! empty($filters['period'])) {
            $base->where('c.created_at', '>=', now()->subDays((int) $filters['period']));
        }
        if (! empty($filters['reviewed'])) {
            $base->whereExists(fn ($query) => $query->selectRaw('1')->from('message_reviews as r')
                ->join('messages as rm', function ($join): void {
                    $join->on('rm.id', '=', 'r.message_id')->on('rm.brand_id', '=', 'r.brand_id')->on('rm.organization_id', '=', 'r.organization_id');
                })->whereColumn('rm.conversation_id', 'c.id')->whereColumn('r.brand_id', 'c.brand_id')->whereColumn('r.organization_id', 'c.organization_id'));
        }
        $page = (int) ($filters['page'] ?? 1);
        $total = (clone $base)->count();
        $pages = max(1, (int) ceil($total / 50));
        $page = min($page, $pages);
        $columns = ['c.id', 'c.mode', 'c.stage', 'c.outcome', 'c.assigned_user_id', 'c.created_at', 'c.updated_at',
            'c.last_client_message_at', 'c.summary', 'c.state', 'contact.name as contact_name',
            'contact.email as contact_email', 'contact.phone as contact_phone', 'channel.type as channel_type'];
        $lastMessage = DB::table('messages as latest')->selectRaw('max(latest.created_at)')->whereColumn('latest.conversation_id', 'c.id')
            ->whereColumn('latest.brand_id', 'c.brand_id')->whereColumn('latest.organization_id', 'c.organization_id');
        $rows = (clone $base)->select($columns)->selectSub($lastMessage, 'last_message_at')
            ->orderByRaw('COALESCE((SELECT MAX(latest.created_at) FROM messages AS latest WHERE latest.conversation_id = c.id AND latest.brand_id = c.brand_id AND latest.organization_id = c.organization_id), c.created_at) '.($sort === 'oldest' ? 'asc' : 'desc'))->orderByDesc('c.id')->forPage($page, 50)->get();
        $messages = DB::table('messages')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
            ->whereIn('conversation_id', $rows->pluck('id')->all())->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'conversation_id', 'role', 'text', 'meta', 'created_at', 'delivered_at', 'delivery_error'])->groupBy('conversation_id');
        $reviewCounts = DB::table('message_reviews as r')->join('messages as m', function ($join): void {
            $join->on('m.id', '=', 'r.message_id')->on('m.brand_id', '=', 'r.brand_id')->on('m.organization_id', '=', 'r.organization_id');
        })->where('r.organization_id', $context->organizationId)->where('r.brand_id', $context->brandId)
            ->whereIn('m.conversation_id', $rows->pluck('id')->all())->selectRaw('m.conversation_id, count(*) as total')->groupBy('m.conversation_id')->pluck('total', 'm.conversation_id');
        $list = $rows->map(fn ($row): array => [
            'id' => $row->id, 'name' => $row->contact_name ?: 'Гость · '.substr($row->id, -6),
            'mode' => $row->mode, 'stage' => $row->stage, 'outcome' => $row->outcome,
            'channel' => $row->channel_type ?: 'Не указан', 'last_message' => $messages->get($row->id)?->first()?->text,
            'updated_at' => $row->last_message_at ?? $row->created_at, 'assigned_user_id' => $row->assigned_user_id,
            'review_count' => (int) ($reviewCounts[$row->id] ?? 0),
            'waiting_minutes' => ! in_array($row->stage, ['done', 'lost', 'spam'], true) && $messages->get($row->id)?->first()?->role === 'client'
                ? max(0, (int) Carbon::parse($messages->get($row->id)->first()->created_at)->diffInMinutes(now())) : null,
        ])->all();

        $detail = null;
        if ($selected !== null) {
            $row = $rows->firstWhere('id', $selected);
            if (! $row) {
                $row = (clone $base)->where('c.id', $selected)->first($columns) ?? abort(404);
            }
            $state = json_decode($row->state ?: '{}', true);
            $values = is_array($state) && is_array($state['slots'] ?? null) ? $state['slots'] : [];
            $extraLabels = collect($publishedFields)->filter(fn ($field) => is_array($field) && is_string($field['id'] ?? null) && ! array_key_exists($field['id'], self::SLOTS))
                ->mapWithKeys(fn ($field) => [$field['id'] => $field['name']])->all();
            $slotLabels = self::SLOTS + $extraLabels;
            $items = $messages->get($row->id) ?? DB::table('messages')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('conversation_id', $row->id)->orderByDesc('created_at')
                ->orderByDesc('id')->limit(100)->get(['id', 'conversation_id', 'role', 'text', 'meta', 'created_at', 'delivered_at', 'delivery_error']);
            $detail = [
                'id' => $row->id, 'name' => $row->contact_name ?: 'Гость · '.substr($row->id, -6), 'channel' => $row->channel_type ?: 'Не указан',
                'stage' => $row->stage, 'outcome' => $row->outcome, 'mode' => $row->mode,
                'assigned_user_id' => $row->assigned_user_id, 'summary' => $row->summary,
                'email' => $row->contact_email, 'phone' => $row->contact_phone,
                'slots' => collect($slotLabels)->map(fn ($label, $key): array => ['key' => $key, 'label' => $label, 'value' => $this->slot($values[$key] ?? null)])->values()->all(),
                'messages' => $items->reverse()->values()->map(function ($message) use ($showMeta): array {
                    $meta = json_decode($message->meta ?: '{}', true);
                    $meta = is_array($meta) ? $meta : [];

                    return ['id' => $message->id, 'role' => $message->role, 'text' => $message->text,
                        'created_at' => $message->created_at, 'attachment_name' => is_string($meta['attachment_name'] ?? null) ? $meta['attachment_name'] : null,
                        'delivery' => $message->role !== 'manager' ? null : (is_string($message->delivery_error) ? 'error' : ($message->delivered_at !== null ? 'sent' : 'fake')),
                        'meta' => ! $showMeta ? [] : [
                            'intent' => $this->slot($meta['intent'] ?? null), 'goal' => $this->slot($meta['goal'] ?? null),
                            'tools' => $this->slot($meta['tools'] ?? null), 'guard' => $this->slot($meta['guard'] ?? null),
                            'model' => $this->slot($meta['model'] ?? null),
                        ]];
                })->all(),
                'transfers' => DB::table('audit_logs')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                    ->where('subject_type', 'conversation')->where('subject_id', $row->id)->where('action', 'seller.dialog.transferred')
                    ->orderByDesc('created_at')->limit(20)->get(['after', 'created_at'])->map(function ($event): array {
                        $after = json_decode($event->after ?: '{}', true);

                        return ['name' => is_string($after['recipient_name'] ?? null) ? $after['recipient_name'] : 'Сотрудник', 'created_at' => $event->created_at];
                    })->all(),
                'tasks' => DB::table('seller_tasks')->where('organization_id', $context->organizationId)
                    ->where('brand_id', $context->brandId)->where('conversation_id', $row->id)
                    ->orderByDesc('created_at')->limit(20)->get(['id', 'type', 'status', 'due_at', 'payload'])->map(function ($task): array {
                        $payload = json_decode($task->payload ?: '{}', true);

                        return ['id' => $task->id, 'type' => $task->type, 'status' => $task->status, 'due_at' => $task->due_at, 'text' => is_string($payload['text'] ?? null) ? $payload['text'] : null];
                    })->all(),
            ];
        }

        return ['dialogs' => $list, 'dialog' => $detail, 'mineCounts' => $counts, 'managerOptions' => $managerOptions, 'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total]];
    }

    private function slot(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        return is_array($value) && array_is_list($value) ? implode(', ', array_filter($value, 'is_scalar')) : null;
    }
}
