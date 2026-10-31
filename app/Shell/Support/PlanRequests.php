<?php

namespace App\Shell\Support;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Support\Contracts\PlanRequestCommand;
use App\Shell\Support\Contracts\PlanRequestDeliveryStatus;
use App\Shell\Support\Contracts\PlanRequestPort;
use App\Shell\Support\Contracts\PlanRequestResult;
use App\Shell\Support\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class PlanRequests implements PlanRequestPort
{
    public function submit(Request $request, PlanRequestCommand $command): PlanRequestResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Plan requests require the outermost transaction.');
        }
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new LogicException('Plan request identity unavailable.');
        }
        $context = app(TenantContext::class);
        $identity = hash_hmac('sha256', json_encode(['type' => 'plan_request', 'plan_id' => $command->planId,
            'brand_id' => $context->brandId], JSON_THROW_ON_ERROR), hash('sha256', 'plan-request-v1:'.$key, true));

        $submission = app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $context, $command, $identity): SubmissionResult {
            abort_unless($context->source === 'session' && $context->actorId === $request->user()?->getAuthIdentifier()
                && $context->brandId !== null && $context->brandId === $request->session()->get('brand_id'), 403);
            $member = app(SupportAccess::class)->lock();
            $owner = DB::table('organizations')->where('id', $context->organizationId)->value('owner_user_id');
            Gate::authorize('billing.manage');
            abort_unless($member->role === 'owner' && $owner === $member->user_id, 403);

            $existing = Ticket::query()->where('user_id', $member->user_id)->where('submission_key', $command->submissionKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->type !== 'plan_request' || ! is_string($existing->submission_hash) || ! hash_equals($existing->submission_hash, $identity)) {
                    throw new HttpException(409, '');
                }

                return new SubmissionResult($existing, false);
            }

            $plan = DB::table('plans')->where('id', $command->planId)->where('is_public', true)->sharedLock()->first(['id', 'name']);
            abort_unless($plan, 404);
            $ticket = Ticket::create(['user_id' => $member->user_id, 'type' => 'plan_request', 'subject' => 'plan_request', 'module' => 'cabinet',
                'body' => "Тариф: {$plan->name}\nИдентификатор тарифа: {$plan->id}\nИдентификатор бренда: {$context->brandId}",
                'submission_key' => $command->submissionKey, 'submission_hash' => $identity]);
            $event = new DomainEvent(['event_id' => $ticket->id, 'schema_version' => 1, 'type' => 'shell.plan_request.created',
                'occurred_at' => now()->toIso8601String(), 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                'actor_id' => $member->user_id, 'aggregate' => ['type' => 'ticket', 'id' => $ticket->id, 'version' => 1],
                'data' => ['ticket_id' => $ticket->id]]);
            app(AuditWriter::class)->record($context, new AuditEntry('billing.plan_request.created', 'ticket', $ticket->id,
                after: ['plan_id' => $plan->id], eventId: $ticket->id));
            app(NotificationDispatcher::class)->enqueue($event);

            return new SubmissionResult($ticket, true);
        });

        try {
            $delivery = app(TicketDelivery::class);
            $status = ($submission->created ? $delivery->send($submission->ticket) : $delivery->existing($submission->ticket))['status'];
        } catch (Throwable) {
            // A failure after commit may have occurred before or during delivery.
            $status = 'uncertain';
        }

        return new PlanRequestResult($submission->ticket->id, $submission->created,
            PlanRequestDeliveryStatus::tryFrom($status) ?? PlanRequestDeliveryStatus::Uncertain);
    }
}
