<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Engine\LeadState;
use App\Seller\Quality\ReportIssueLinks;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class DialogsController
{
    public function index(Request $request, DialogReader $reader, ?string $dialog = null, bool $mine = false): Response
    {
        $input = $request->validate([
            'mode' => ['sometimes', 'string', Rule::in(['all', 'mine', 'bot', 'suggest', 'manager', 'paused', 'waiting', 'closed'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['newest', 'oldest'])],
            'channel' => ['sometimes', Rule::in(['telegram', 'widget', 'api', 'email', 'whatsapp'])],
            'stage' => ['sometimes', Rule::in(LeadState::STAGES)],
            'manager' => ['sometimes', 'ulid'],
            'outcome' => ['sometimes', Rule::in(LeadState::OUTCOMES)],
            'period' => ['sometimes', Rule::in(['7', '30', '90'])],
            'report_day' => ['sometimes', 'date_format:Y-m-d'],
            'reviewed' => ['sometimes', 'in:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        if ($mine) {
            $input['mode'] = 'mine';
        }
        $data = app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($reader, $dialog, $input): array {
            Gate::forUser($actor)->authorize('seller.dialogs.read');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $manager = $member->role === 'member' && ($member->settings['sales_manager'] ?? false);

            try {
                $data = $reader->read($context, $actor->id, $manager, ! $manager && $member->role !== 'viewer', $dialog, $input['mode'] ?? 'all', trim($input['search'] ?? ''), $input['sort'] ?? 'newest', $input);
            } catch (QueryException $exception) {
                report($exception);

                return ['dialogs' => [], 'dialog' => null, 'loadError' => true];
            }
            $data['transferOptions'] = $dialog && Gate::forUser($actor)->allows('seller.dialogues.operate') ? app(DialogTransferController::class)->candidates($context) : [];
            $data['readySuggestion'] = $dialog && ($data['dialog']['mode'] ?? null) === 'suggest' && Gate::forUser($actor)->allows('seller.dialogues.operate')
                ? collect(app(ReadySuggestions::class)->read($context, $actor->id, $member))->firstWhere('conversation_id', $dialog)['suggestion'] ?? null
                : null;
            $data['evaluationId'] = $dialog && ($data['dialog']['id'] ?? null) === $dialog && Gate::forUser($actor)->allows('seller.knowledge.write')
                ? DB::table('seller_evaluations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('conversation_id', $dialog)->orderByDesc('created_at')->orderByDesc('id')->value('id')
                : null;
            $data['reportDay'] = null;
            if ($dialog && isset($input['report_day']) && ($data['dialog']['id'] ?? null) === $dialog && Gate::forUser($actor)->allows('seller.knowledge.write')) {
                $scope = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId];
                $reports = DB::table('seller_supervisor_reports')->where($scope)->where('report_date', $input['report_day'])->get(['issues'])
                    ->map(fn ($row) => ['issues' => json_decode($row->issues, true) ?: []]);
                $start = CarbonImmutable::parse($input['report_day'], $brand->timezone)->startOfDay();
                $evaluations = DB::table('seller_evaluations')->where($scope)->whereNotNull('conversation_id')->where('created_at', '>=', $start->utc())->where('created_at', '<', $start->addDay()->utc())->get(['id', 'conversation_id', 'issues'])
                    ->map(fn ($row) => ['id' => $row->id, 'conversation_id' => $row->conversation_id, 'issues' => json_decode($row->issues, true) ?: []]);
                $linked = app(ReportIssueLinks::class)->enrich($reports, $evaluations)->flatMap(fn ($report) => $report['issues'])->contains(fn ($issue) => ($issue['conversation_id'] ?? null) === $dialog);
                $data['reportDay'] = $linked ? $input['report_day'] : null;
            }
            $templates = app()->bound(MessageTemplates::class) ? app(MessageTemplates::class) : app(DefaultMessageTemplates::class);
            $data['messageTemplates'] = $templates->forBrand($context);

            return $data;
        });

        return Inertia::render($mine ? 'Seller/Dialogs/Mine' : 'Seller/Dialogs/Index', $data + ['filters' => ['mode' => $input['mode'] ?? 'all', 'search' => $input['search'] ?? '', 'sort' => $input['sort'] ?? 'newest', 'channel' => $input['channel'] ?? '', 'stage' => $input['stage'] ?? '', 'manager' => $input['manager'] ?? '', 'outcome' => $input['outcome'] ?? '', 'period' => $input['period'] ?? '', 'reviewed' => $input['reviewed'] ?? '', 'page' => (int) ($input['page'] ?? 1)]]);
    }

    public function mine(Request $request, DialogReader $reader, ?string $dialog = null): Response
    {
        return $this->index($request, $reader, $dialog, true);
    }

    public function mode(Request $request, string $dialog): RedirectResponse
    {
        $input = $request->validate(['action' => ['required', Rule::in(['take', 'return'])]]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $input): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $row = DB::table('conversations')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $dialog)->lockForUpdate()->first() ?? abort(404);
            $manager = $member->role === 'member' && ($member->settings['sales_manager'] ?? false);
            if ($manager && $row->assigned_user_id !== $actor->id && ! ($row->mode === 'manager' && $row->assigned_user_id === null)) {
                abort(404);
            }
            if ($input['action'] === 'take') {
                if ($row->mode === 'manager' && $row->assigned_user_id !== null && $row->assigned_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['action' => 'Диалог уже ведёт другой сотрудник.']);
                }
                if (! in_array($row->mode, ['bot', 'suggest', 'manager'], true)) {
                    throw ValidationException::withMessages(['action' => 'Этот диалог сейчас нельзя забрать.']);
                }
                $after = ['mode' => 'manager', 'assigned_user_id' => $actor->id, 'taken_at' => now()];
            } else {
                if ($row->mode !== 'manager' || $row->assigned_user_id === null) {
                    throw ValidationException::withMessages(['action' => 'Диалог уже у бота.']);
                }
                if ($manager && $row->assigned_user_id !== $actor->id) {
                    abort(404);
                }
                $after = ['mode' => 'bot', 'assigned_user_id' => null, 'taken_at' => null];
            }
            DB::table('conversations')->where('id', $row->id)->update($after + ['version' => $row->version + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.dialog.mode_changed', 'conversation', $row->id,
                before: ['mode' => $row->mode, 'assigned_user_id' => $row->assigned_user_id],
                after: ['mode' => $after['mode'], 'assigned_user_id' => $after['assigned_user_id']]));
        });

        return redirect('/seller/dialogs/'.$dialog);
    }
}
