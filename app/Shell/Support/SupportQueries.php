<?php

namespace App\Shell\Support;

use App\Shell\Support\Models\Ticket;
use App\Shell\Support\Models\TicketAttachment;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class SupportQueries
{
    public function __construct(private SupportConsent $consent, private TicketDelivery $delivery) {}

    public function index(Request $request, bool $includeTickets = true): array
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']], __('Shell/Support/messages.validation'), __('Shell/Support/messages.attributes'));
        $tickets = $includeTickets ? Ticket::query()->where('user_id', app(TenantContext::class)->actorId)->where('type', 'support')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20) : null;
        $canSubmit = Gate::allows('create', Ticket::class);

        $context = app(TenantContext::class);

        return ['submission_key' => (string) Str::ulid(),
            'submission_scope' => hash_hmac('sha256', $context->organizationId.'|'.$context->actorId, (string) config('app.key')),
            'tickets' => ['data' => $tickets?->getCollection()->map(fn (Ticket $ticket) => $this->ticket($ticket))->all() ?? [],
                'current_page' => $tickets?->currentPage() ?? 1, 'last_page' => $tickets?->lastPage() ?? 1, 'per_page' => $tickets?->perPage() ?? 20, 'total' => $tickets?->total() ?? 0],
            'topics' => $this->options(__('Shell/Support/messages.topics')), 'modules' => $this->options(__('Shell/Support/messages.modules')),
            'upload' => ['max_files' => (int) config('features.E1-12.defaults.max_files'), 'max_file_kb' => (int) config('features.E1-12.defaults.max_file_kb'),
                'accept' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain']],
            'access' => ['duration_hours' => 72, 'entry_available' => false], 'delivery' => $this->delivery->configuration(),
            'can_submit' => $canSubmit, 'submit_unavailable_reason' => $canSubmit ? null : __('Shell/Support/messages.viewer_readonly')];
    }

    public function ticket(Ticket $ticket): array
    {
        $grant = $this->consent->current($ticket);
        $grantStatus = $grant ? $this->consent->status($grant) : null;

        return ['id' => $ticket->id, 'subject' => $ticket->subject, 'subject_label' => __('Shell/Support/messages.topics.'.$ticket->subject),
            'module' => $ticket->module, 'module_label' => __('Shell/Support/messages.modules.'.$ticket->module), 'body' => $ticket->body,
            'status' => $ticket->status, 'created_at' => $ticket->created_at->toIso8601String(),
            'delivery_status' => in_array($ticket->delivery_status, ['pending', 'sending'], true) ? 'uncertain' : $ticket->delivery_status,
            'attachments' => TicketAttachment::query()->where('ticket_id', $ticket->id)->where('user_id', $ticket->user_id)->orderBy('id')->get()
                ->map(fn (TicketAttachment $file) => ['id' => $file->id, 'name' => $file->name, 'mime_type' => $file->mime_type, 'size_bytes' => $file->size_bytes,
                    'download_url' => route('help.support.attachments.show', ['ticket' => $ticket->id, 'attachment' => $file->id])])->all(),
            'access' => $grant ? ['id' => $grant->id, 'granted_at' => $grant->granted_at->toIso8601String(), 'expires_at' => $grant->expires_at->toIso8601String(),
                'revoked_at' => $grant->revoked_at?->toIso8601String(), 'status' => $grantStatus, 'can_revoke' => $grantStatus === 'active' && Gate::allows('revoke', $ticket)] : null];
    }

    private function options(array $items): array
    {
        $result = [];
        foreach ($items as $value => $label) {
            $result[] = ['value' => $value, 'label' => $label];
        }

        return $result;
    }
}
