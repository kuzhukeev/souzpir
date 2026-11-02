<?php

namespace App\Seller\Customers;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
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
        $filter = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'type' => ['nullable', 'in:newbie,experienced,for_self,prices_only,unknown'], 'stage' => ['nullable', 'string', 'max:32'], 'city' => ['nullable', 'string', 'max:120'], 'outcome' => ['nullable', 'string', 'max:64'], 'manager' => ['nullable', 'string', 'max:120'], 'channel' => ['nullable', 'string', 'max:32'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $data = $this->access($request, 'seller.customers.read', function (User $user, TenantContext $tenant) use ($filter): array {
            $contacts = $this->scope('contacts', $tenant)->whereNull('merged_into_id')->orderByDesc('created_at')->get(['id', 'name', 'phone', 'email']);
            $rows = $contacts->map(function ($contact) use ($tenant): array {
                $conversation = $this->scope('conversations', $tenant)->where('contact_id', $contact->id)->orderByDesc('updated_at')->first();
                $state = $conversation ? json_decode($conversation->state, true) : [];
                $tasks = $this->scope('seller_tasks', $tenant)->whereIn('conversation_id', $this->scope('conversations', $tenant)->where('contact_id', $contact->id)->select('id'))->where('status', 'new')->count();
                $manager = $conversation?->assigned_user_id ? DB::table('users')->where('id', $conversation->assigned_user_id)->value('name') : null;
                $channel = $conversation?->channel_account_id ? $this->scope('channel_accounts', $tenant)->where('id', $conversation->channel_account_id)->value('type') : null;

                return ['id' => $contact->id, 'name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email,
                    'type' => $this->clientType($conversation?->state), 'city' => data_get($state, 'slots.city'), 'stage' => $conversation?->stage,
                    'outcome' => $conversation?->outcome, 'manager' => $manager, 'channel' => $channel, 'tasks' => $tasks, 'last_at' => $conversation?->updated_at];
            });
            $filtered = $rows->filter(function (array $row) use ($filter): bool {
                $search = mb_strtolower($filter['search'] ?? '');

                return ($search === '' || str_contains(mb_strtolower(implode(' ', [$row['name'], $row['phone'], $row['email']])), $search))
                    && (empty($filter['type']) || $row['type'] === $filter['type']) && (empty($filter['stage']) || $row['stage'] === $filter['stage'])
                    && (empty($filter['outcome']) || $row['outcome'] === $filter['outcome']) && (empty($filter['channel']) || $row['channel'] === $filter['channel'])
                    && (empty($filter['city']) || str_contains(mb_strtolower((string) $row['city']), mb_strtolower($filter['city'])))
                    && (empty($filter['manager']) || str_contains(mb_strtolower((string) $row['manager']), mb_strtolower($filter['manager'])))
                    && (empty($filter['from']) || ($row['last_at'] && substr($row['last_at'], 0, 10) >= $filter['from']))
                    && (empty($filter['to']) || ($row['last_at'] && substr($row['last_at'], 0, 10) <= $filter['to']));
            })->values();

            return ['customers' => $filtered, 'filters' => $filter, 'counts' => ['total' => $rows->count(),
                'contact' => $rows->whereIn('outcome', ['full_contact', 'partial_contact'])->count(),
                'handoff' => $rows->where('outcome', 'handoff')->count(), 'stale' => $rows->where('outcome', 'stale')->count()]];
        });

        return Inertia::render('Seller/Customers/Index', $data);
    }

    public function show(Request $request, string $contact): mixed
    {
        $data = $this->access($request, 'seller.customers.read', function (User $user, TenantContext $tenant) use ($contact): array {
            $customer = $this->scope('contacts', $tenant)->where('id', $contact)->whereNull('merged_into_id')->first(['id', 'name', 'phone', 'email']) ?? abort(404);
            $conversations = $this->scope('conversations', $tenant)->where('contact_id', $contact)->orderByDesc('updated_at')->get(['id', 'state', 'stage', 'outcome', 'mode', 'updated_at', 'assigned_user_id']);
            $latest = $conversations->first();
            $state = $latest ? json_decode($latest->state, true) : [];
            $history = [];
            $messages = $this->scope('messages', $tenant)->whereIn('conversation_id', $conversations->pluck('id'))->orderByDesc('created_at')->get(['id', 'role', 'meta', 'created_at']);
            foreach ($messages as $message) {
                $meta = json_decode($message->meta, true);
                foreach (data_get($meta, 'extraction.slot_updates', []) as $slot => $value) {
                    $history[] = ['slot' => $slot, 'value' => $value, 'role' => $message->role, 'at' => $message->created_at];
                }
            }
            $tasks = $this->scope('seller_tasks', $tenant)->whereIn('conversation_id', $conversations->pluck('id'))->orderBy('due_at')->get(['id', 'type', 'payload', 'status', 'due_at']);
            $notes = $this->scope('seller_customer_notes', $tenant)->where('contact_id', $contact)->orderByDesc('created_at')->get(['id', 'body', 'author_id', 'created_at']);

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

            return ['canReadDialogs' => Gate::forUser($user)->allows('seller.dialogs.read'), 'managers' => $eligible, 'assignedUserId' => $latest?->assigned_user_id, 'canAssign' => $canAssign, 'manager' => $latest?->assigned_user_id ? DB::table('users')->where('id', $latest->assigned_user_id)->value('name') : null, 'customer' => $customer, 'clientType' => $this->clientType($latest?->state), 'slots' => data_get($state, 'slots', []),
                'conversations' => $conversations->map(fn ($row) => ['id' => $row->id, 'stage' => $row->stage, 'outcome' => $row->outcome, 'mode' => $row->mode, 'updated_at' => $row->updated_at]),
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
            if ($manager && $conversation->assigned_user_id && $conversation->assigned_user_id !== $user->id) {
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
        $filters = $request->validate(['mine' => ['nullable', 'in:1'], 'overdue' => ['nullable', 'in:1']]);
        $data = $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant) use ($filters): array {
            $query = $this->scope('seller_tasks', $tenant);
            if (! empty($filters['mine'])) {
                $query->where('assigned_user_id', $user->id);
            }
            if (! empty($filters['overdue'])) {
                $query->where('due_at', '<', now())->where('status', 'new');
            }
            $tasks = $query->orderByRaw('CASE WHEN due_at < CURRENT_TIMESTAMP AND status = ? THEN 0 ELSE 1 END', ['new'])->orderBy('due_at')->orderByDesc('created_at')->get(['id', 'conversation_id', 'type', 'payload', 'due_at', 'status', 'priority', 'assigned_user_id'])->map(function ($task) {
                $task->overdue = $task->due_at !== null && $task->status === 'new' && Carbon::parse($task->due_at)->isPast();

                return $task;
            });

            return ['tasks' => $tasks, 'filters' => $filters];
        });

        return Inertia::render('Seller/Customers/Tasks', $data);
    }

    public function updateTask(Request $request, string $task): mixed
    {
        $input = $request->validate(['status' => ['required', 'in:new,done,cancelled']]);
        $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant) use ($task, $input): void {
            $row = $this->scope('seller_tasks', $tenant)->where('id', $task)->first() ?? abort(404);
            $this->scope('seller_tasks', $tenant)->where('id', $task)->update(['status' => $input['status'], 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.task.status.updated', 'seller_task', $task, before: ['status' => $row->status], after: ['status' => $input['status']]));
        });

        return back();
    }
}
