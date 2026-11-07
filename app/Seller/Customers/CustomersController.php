<?php

namespace App\Seller\Customers;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CustomersController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function scope(string $table, TenantContext $tenant): Builder
    {
        return DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
    }

    private function clientType(?string $state): string
    {
        $knowledge = new KnowledgeSnapshot(array_fill_keys(KnowledgeSnapshot::FILES, []), '', '');
        $data = $state ? json_decode($state, true) : [];

        return (new Policy($knowledge))->clientType(new LeadState(is_array($data) ? $data : []));
    }

    public function index(Request $request): mixed
    {
        $channel = $request->query('channel');
        abort_if($channel !== null && $channel !== '' && ! in_array($channel, ['telegram', 'vk', 'whatsapp', 'api', 'widget', 'site', 'web', 'email'], true), 422, 'Недопустимый канал.');

        $filter = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'type' => ['nullable', 'in:newbie,experienced,for_self,prices_only,unknown'], 'stage' => ['nullable', 'string', 'max:32'], 'city' => ['nullable', 'string', 'max:120'], 'outcome' => ['nullable', 'string', 'max:64'], 'manager' => ['nullable', 'string', 'max:120'], 'channel' => ['nullable', 'in:telegram,vk,whatsapp,api,widget,site,web,email'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,20,50']]);
        $data = $this->access($request, 'seller.customers.read', function (User $user, TenantContext $tenant) use ($filter): array {
            $contacts = $this->scope('contacts', $tenant)->whereNull('merged_into_id')->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'name', 'phone', 'email', 'city']);
            $conversations = $this->scope('conversations', $tenant)->whereIn('contact_id', $contacts->pluck('id'))->orderByDesc('updated_at')->get();
            $byContact = $conversations->groupBy('contact_id');
            $tasks = $this->scope('seller_tasks', $tenant)->whereIn('status', ['new', 'in_progress'])
                ->where(fn ($query) => $query->whereIn('contact_id', $contacts->pluck('id'))->orWhereIn('conversation_id', $conversations->pluck('id')))
                ->get(['id', 'contact_id', 'conversation_id']);
            $managers = DB::table('users')->whereIn('id', $conversations->pluck('assigned_user_id')->filter()->unique())->pluck('name', 'id');
            $channels = $this->scope('channel_accounts', $tenant)->whereIn('id', $conversations->pluck('channel_account_id')->filter()->unique())->pluck('type', 'id');
            $rows = $contacts->map(function ($contact) use ($byContact, $tasks, $managers, $channels): array {
                $dialogs = $byContact->get($contact->id, collect());
                $conversation = $dialogs->first();
                $state = $conversation ? json_decode($conversation->state, true) : [];
                $outcomes = $dialogs->pluck('outcome')->filter()->all();
                $bestOutcome = collect(['full_contact', 'handoff', 'partial_contact', 'paused', 'stale', 'declined', 'spam'])->first(fn ($value) => in_array($value, $outcomes, true));
                $dialogIds = $dialogs->pluck('id')->all();
                $taskCount = $tasks->filter(fn ($task) => $task->contact_id === $contact->id || in_array($task->conversation_id, $dialogIds, true))->count();
                $manager = $conversation?->assigned_user_id ? $managers->get($conversation->assigned_user_id) : null;
                $channel = $conversation?->channel_account_id ? $channels->get($conversation->channel_account_id) : null;

                return ['id' => $contact->id, 'name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email,
                    'company' => data_get($state, 'slots.company') ?: data_get($state, 'slots.legal'),
                    'type' => $this->clientType($conversation?->state), 'city' => $contact->city ?: data_get($state, 'slots.city'), 'stage' => $conversation?->stage,
                    'outcome' => $conversation?->outcome, 'best_outcome' => $bestOutcome, 'conversation_id' => $conversation?->id, 'manager' => $manager, 'channel' => $channel, 'tasks' => $taskCount, 'last_at' => $conversation?->updated_at];
            });
            $filtered = $rows->filter(function (array $row) use ($filter): bool {
                $search = mb_strtolower($filter['search'] ?? '');

                return ($search === '' || str_contains(mb_strtolower(implode(' ', [$row['name'], $row['phone'], $row['email']])), $search))
                    && (empty($filter['type']) || $row['type'] === $filter['type']) && (empty($filter['stage']) || $row['stage'] === $filter['stage'])
                    && (empty($filter['outcome']) || $row['outcome'] === $filter['outcome']) && (empty($filter['channel']) || ($filter['channel'] === 'widget' ? in_array($row['channel'], ['widget', 'site', 'web'], true) : $row['channel'] === $filter['channel']))
                    && (empty($filter['city']) || str_contains(mb_strtolower((string) $row['city']), mb_strtolower($filter['city'])))
                    && (empty($filter['manager']) || str_contains(mb_strtolower((string) $row['manager']), mb_strtolower($filter['manager'])))
                    && (empty($filter['from']) || ($row['last_at'] && substr($row['last_at'], 0, 10) >= $filter['from']))
                    && (empty($filter['to']) || ($row['last_at'] && substr($row['last_at'], 0, 10) <= $filter['to']));
            })->values();

            $page = (int) ($filter['page'] ?? 1);
            $perPage = (int) ($filter['per_page'] ?? 20);

            return ['customers' => $filtered->forPage($page, $perPage)->values(), 'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $filtered->count(), 'lastPage' => max(1, (int) ceil($filtered->count() / $perPage))], 'filters' => $filter, 'counts' => ['total' => $rows->count(),
                'contact' => $rows->whereIn('outcome', ['full_contact', 'partial_contact'])->count(),
                'handoff' => $rows->where('outcome', 'handoff')->count(), 'stale' => $rows->where('outcome', 'stale')->count()]];
        });

        return Inertia::render('Seller/Customers/Index', $data);
    }

    public function show(Request $request, string $contact): mixed
    {
        $data = $this->access($request, 'seller.customers.read', function (User $user, TenantContext $tenant) use ($contact): array {
            $customer = $this->scope('contacts', $tenant)->where('id', $contact)->whereNull('merged_into_id')->first(['id', 'name', 'phone', 'email', 'city', 'external_ids']) ?? abort(404);
            $customer->external_ids = is_string($customer->external_ids) ? (json_decode($customer->external_ids, true) ?: []) : [];
            $conversations = $this->scope('conversations', $tenant)->where('contact_id', $contact)->orderByDesc('updated_at')->get(['id', 'state', 'stage', 'outcome', 'mode', 'updated_at', 'assigned_user_id', 'channel_account_id']);
            $latest = $conversations->first();
            $state = $latest ? json_decode($latest->state, true) : [];
            $customer->company = data_get($state, 'slots.company') ?: data_get($state, 'slots.legal');
            $customer->city = $customer->city ?: data_get($state, 'slots.city');
            $channelNames = $this->scope('channel_accounts', $tenant)->whereIn('id', $conversations->pluck('channel_account_id')->filter()->unique())->pluck('type', 'id');
            $history = [];
            $messages = $this->scope('messages', $tenant)->whereIn('conversation_id', $conversations->pluck('id'))->orderByDesc('created_at')->get(['id', 'role', 'meta', 'created_at']);
            foreach ($messages as $message) {
                $meta = json_decode($message->meta, true);
                foreach (data_get($meta, 'extraction.slot_updates', []) as $slot => $value) {
                    $history[] = ['slot' => $slot, 'value' => $value, 'role' => $message->role, 'at' => $message->created_at];
                }
            }
            $tasks = $this->scope('seller_tasks', $tenant)->where(fn ($query) => $query->where('contact_id', $contact)->orWhereIn('conversation_id', $conversations->pluck('id')))->orderBy('due_at')->get(['id', 'type', 'payload', 'status', 'due_at', 'assigned_user_id', 'conversation_id']);
            $assignees = DB::table('users')->whereIn('id', $tasks->pluck('assigned_user_id')->filter()->unique())->pluck('name', 'id');
            $conversationChannels = $conversations->mapWithKeys(fn ($row) => [$row->id => $channelNames->get($row->channel_account_id)]);
            $tasks->each(function ($task) use ($assignees, $conversationChannels): void {
                $task->assignee_name = $task->assigned_user_id ? $assignees->get($task->assigned_user_id) : null;
                $task->channel = $task->conversation_id ? $conversationChannels->get($task->conversation_id) : null;
            });
            $notes = $this->scope('seller_customer_notes', $tenant)->where('contact_id', $contact)->orderByDesc('created_at')->get(['id', 'body', 'author_id', 'created_at']);
            $authors = DB::table('users')->whereIn('id', $notes->pluck('author_id')->filter()->unique())->pluck('name', 'id');
            $notes->each(fn ($note) => $note->author_name = $authors->get($note->author_id));

            $eligible = Membership::withoutGlobalScopes()->with('user')->where('organization_id', $tenant->organizationId)->where('status', 'active')->get()
                ->filter(fn ($member) => $member->user && $member->permitsBrand($tenant->brandId) && (in_array($member->role, ['owner', 'admin'], true) || ($member->role === 'member' && ($member->settings['sales_manager'] ?? false))))
                ->map(fn ($member) => ['id' => $member->user_id, 'name' => $member->user->name])->values();

            $canAssign = Gate::forUser($user)->allows('seller.dialogues.operate');
            $actor = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)->where('user_id', $user->id)->where('status', 'active')->first();
            if ($actor?->role === 'member' && ($actor->settings['sales_manager'] ?? false)) {
                $eligible = $eligible->where('id', $user->id)->values();
                $canAssign = $canAssign && (! $latest?->assigned_user_id || $latest->assigned_user_id === $user->id);
            }
            if (! $canAssign) {
                $eligible = collect();
            }

            $customFieldLabels = [];
            try {
                $publishedFields = app(KnowledgeRepository::class)->published($tenant)->policy()['client_fields'] ?? [];
                $customFieldLabels = collect($publishedFields)->filter(fn ($field) => is_array($field) && is_string($field['id'] ?? null))
                    ->mapWithKeys(fn ($field) => [$field['id'] => $field['name']])->all();
            } catch (KnowledgeNotReady) {
                // нет опубликованной версии — дополнительных полей нет
            }

            return ['canReadDialogs' => Gate::forUser($user)->allows('seller.dialogs.read'), 'managers' => $eligible, 'assignedUserId' => $latest?->assigned_user_id, 'canAssign' => $canAssign, 'manager' => $latest?->assigned_user_id ? DB::table('users')->where('id', $latest->assigned_user_id)->value('name') : null, 'customer' => $customer, 'clientType' => $this->clientType($latest?->state), 'slots' => data_get($state, 'slots', []), 'customFieldLabels' => $customFieldLabels,
                'conversations' => $conversations->map(fn ($row) => ['id' => $row->id, 'stage' => $row->stage, 'outcome' => $row->outcome, 'mode' => $row->mode, 'channel' => $channelNames->get($row->channel_account_id), 'updated_at' => $row->updated_at]),
                'history' => $history, 'tasks' => $tasks, 'notes' => $notes, 'canWrite' => Gate::forUser($user)->allows('seller.customers.write')];
        });

        return Inertia::render('Seller/Customers/Show', $data);
    }

    public function assign(Request $request, string $contact): mixed
    {
        $input = $request->validate(['manager_id' => ['nullable', 'ulid']]);
        $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant) use ($input, $contact): void {
            $this->scope('contacts', $tenant)->where('id', $contact)->whereNull('merged_into_id')->first() ?? abort(404);
            $conversation = $this->scope('conversations', $tenant)->where('contact_id', $contact)->orderByDesc('updated_at')->orderByDesc('id')->first() ?? abort(404);
            $actor = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)->where('user_id', $user->id)->where('status', 'active')->first() ?? abort(403);
            $manager = $actor->role === 'member' && ($actor->settings['sales_manager'] ?? false);
            if ($manager && $conversation?->assigned_user_id && $conversation->assigned_user_id !== $user->id) {
                abort(404);
            }
            if ($manager && ($input['manager_id'] ?? null) !== $user->id && ! (($input['manager_id'] ?? null) === null && $conversation->assigned_user_id === $user->id)) {
                abort(403);
            }
            if (! empty($input['manager_id'])) {
                $member = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)->where('user_id', $input['manager_id'])->where('status', 'active')->first();
                if (! $member || ! $member->permitsBrand($tenant->brandId) || ! (in_array($member->role, ['owner', 'admin'], true) || ($member->role === 'member' && ($member->settings['sales_manager'] ?? false)))) {
                    throw ValidationException::withMessages(['manager_id' => 'Сотрудник недоступен для этого бренда.']);
                }
            }
            $this->scope('conversations', $tenant)->where('id', $conversation->id)->update(['assigned_user_id' => $input['manager_id'] ?? null, 'version' => $conversation->version + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.customer.manager_assigned', 'conversation', $conversation->id, before: ['assigned_user_id' => $conversation->assigned_user_id], after: ['assigned_user_id' => $input['manager_id'] ?? null]));
        });

        return back()->with('status', 'Менеджер назначен.');
    }

    public function remind(Request $request, string $contact): mixed
    {
        $input = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
            'due_at' => ['required', 'date', 'after:now'],
            'manager_id' => ['required', 'ulid'],
        ]);
        $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant) use ($contact, $input): void {
            $this->scope('contacts', $tenant)->where('id', $contact)->whereNull('merged_into_id')->first() ?? abort(404);
            $conversation = $this->scope('conversations', $tenant)->where('contact_id', $contact)->orderByDesc('updated_at')->orderByDesc('id')->first();
            $actor = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)->where('user_id', $user->id)->where('status', 'active')->first() ?? abort(403);
            if ($actor->role === 'member' && ($actor->settings['sales_manager'] ?? false)) {
                abort_if($conversation?->assigned_user_id && $conversation->assigned_user_id !== $user->id, 404);
                abort_unless($input['manager_id'] === $user->id, 403);
            }
            $manager = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)->where('user_id', $input['manager_id'])->where('status', 'active')->first();
            if (! $manager || ! $manager->permitsBrand($tenant->brandId) || ! (in_array($manager->role, ['owner', 'admin'], true) || ($manager->role === 'member' && ($manager->settings['sales_manager'] ?? false)))) {
                throw ValidationException::withMessages(['manager_id' => 'Сотрудник недоступен для этого бренда.']);
            }
            $id = (string) Str::ulid();
            DB::table('seller_tasks')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'conversation_id' => $conversation?->id, 'contact_id' => $contact, 'type' => 'reminder', 'payload' => json_encode(['text' => $input['text']], JSON_UNESCAPED_UNICODE),
                'due_at' => Carbon::parse($input['due_at'])->utc(), 'status' => 'new', 'priority' => 'normal', 'assigned_user_id' => $input['manager_id'], 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.customer.reminder.created', 'seller_task', $id));
        });

        return back()->with('status', 'Напоминание создано.');
    }

    public function note(Request $request, string $contact): mixed
    {
        $input = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $this->access($request, 'seller.customers.write', function (User $user, TenantContext $tenant) use ($input, $contact): void {
            $this->scope('contacts', $tenant)->where('id', $contact)->whereNull('merged_into_id')->first() ?? abort(404);
            $id = (string) Str::ulid();
            DB::table('seller_customer_notes')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'contact_id' => $contact, 'author_id' => $user->id, 'body' => $input['body'], 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.customer.note.created', 'seller_customer_note', $id));
        });

        return back();
    }

    public function tasks(Request $request): mixed
    {
        $filters = $request->validate(['mine' => ['nullable', 'in:1'], 'overdue' => ['nullable', 'in:1'], 'urgent' => ['nullable', 'in:1'], 'type' => ['nullable', 'in:handoff,promise,reminder,unknown_question,note,send_price,send_requisites,stale']]);
        $data = $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant) use ($filters): array {
            $query = $this->scope('seller_tasks', $tenant);
            if (! empty($filters['mine'])) {
                $query->where('assigned_user_id', $user->id);
            }
            if (! empty($filters['overdue'])) {
                $query->where('due_at', '<', now())->whereIn('status', ['new', 'in_progress']);
            }
            if (! empty($filters['urgent'])) {
                $query->where('priority', 'urgent');
            }
            if (! empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }
            $member = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)->where('user_id', $user->id)->where('status', 'active')->first();
            $manager = $member?->role === 'member' && ($member->settings['sales_manager'] ?? false);
            $canReadDialogs = Gate::forUser($user)->allows('seller.dialogs.read');
            $tasks = $query->orderByRaw("CASE WHEN due_at < CURRENT_TIMESTAMP AND status IN ('new', 'in_progress') THEN 0 ELSE 1 END")->orderBy('due_at')->orderByDesc('created_at')->get(['id', 'contact_id', 'conversation_id', 'type', 'payload', 'due_at', 'status', 'priority', 'assigned_user_id'])->map(function ($task) use ($tenant, $user, $manager, $canReadDialogs) {
                $task->overdue = $task->due_at !== null && in_array($task->status, ['new', 'in_progress'], true) && Carbon::parse($task->due_at)->isPast();
                $taskConversation = $task->conversation_id ? $this->scope('conversations', $tenant)->where('id', $task->conversation_id)->first(['id', 'mode', 'assigned_user_id', 'contact_id']) : null;
                $dialog = $canReadDialogs ? $taskConversation : null;
                $visible = $dialog && (! $manager || $dialog->assigned_user_id === $user->id || ($dialog->mode === 'manager' && $dialog->assigned_user_id === null));
                $task->assignee_name = $task->assigned_user_id ? DB::table('users')->where('id', $task->assigned_user_id)->value('name') : null;
                $contactId = $task->contact_id ?: $taskConversation?->contact_id;
                $task->contact_name = $contactId ? $this->scope('contacts', $tenant)->where('id', $contactId)->value('name') : null;
                $task->can_read_dialog = (bool) $visible;
                $task->messages = $visible ? $this->scope('messages', $tenant)->where('conversation_id', $dialog->id)->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get(['role', 'text', 'created_at'])->reverse()->values() : [];

                return $task;
            });

            return ['tasks' => $tasks, 'filters' => $filters];
        });

        return Inertia::render('Seller/Customers/Tasks', $data);
    }

    public function updateTask(Request $request, string $task): mixed
    {
        $input = $request->validate(['status' => ['required', 'in:new,in_progress,done,cancelled']]);
        $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant) use ($task, $input): void {
            $row = $this->scope('seller_tasks', $tenant)->where('id', $task)->first() ?? abort(404);
            $this->scope('seller_tasks', $tenant)->where('id', $task)->update(['status' => $input['status'], 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.task.status.updated', 'seller_task', $task, before: ['status' => $row->status], after: ['status' => $input['status']]));
        });

        return back();
    }
}
