<?php

namespace App\Shell\Support;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Support\Models\SupportAccessGrant;
use App\Shell\Support\Models\Ticket;
use App\Shell\Support\Models\TicketAttachment;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SupportActions
{
    public function __construct(private SupportAccess $access, private AuditWriter $audit) {}

    public function create(#[SensitiveParameter] Request $request, #[SensitiveParameter] array $data): Ticket
    {
        // The controller must return a confirmed post-commit delivery result, never send inside a caller's transaction.
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Ticket creation requires an outermost request transaction.');
        }
        $paths = [];
        $bodyCompleted = false;
        try {
            return app(AuthSessionRegistry::class)->withinActive($request, function () use ($data, &$paths, &$bodyCompleted): Ticket {
                $actor = $this->access->lock();
                Gate::authorize('create', Ticket::class);
                $ticket = Ticket::create(['user_id' => $actor->user_id, 'subject' => $data['subject'], 'module' => $data['module'], 'body' => $data['body']]);
                foreach ($data['attachments'] ?? [] as $file) {
                    $attachment = new TicketAttachment(['ticket_id' => $ticket->id, 'user_id' => $actor->user_id,
                        'name' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 180),
                        'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())]);
                    $attachment->id = (string) Str::ulid();
                    $directory = 'support/'.$actor->organization_id.'/'.$ticket->id;
                    $path = $directory.'/'.$attachment->id.'.bin';
                    $paths[] = $path;
                    if (Storage::disk('local')->putFileAs($directory, $file, $attachment->id.'.bin', ['visibility' => 'private']) !== $path) {
                        throw ValidationException::withMessages(['attachments' => __('Shell/Support/messages.upload_failed')]);
                    }
                    $attachment->storage_path = $path;
                    $attachment->save();
                }
                if ($data['support_access'] ?? false) {
                    $now = CarbonImmutable::now();
                    SupportAccessGrant::create(['ticket_id' => $ticket->id, 'granted_by' => $actor->user_id,
                        'granted_at' => $now, 'expires_at' => $now->addHours(72)]);
                }
                $this->audit->record(app(TenantContext::class), new AuditEntry('support.ticket.created', 'ticket', $ticket->id,
                    after: ['subject' => $ticket->subject, 'module' => $ticket->module, 'attachment_count' => count($paths), 'support_consent' => (bool) ($data['support_access'] ?? false)]));

                // Once the callback returns, a failed COMMIT has an unknown outcome.
                $bodyCompleted = true;

                return $ticket;
            });
        } catch (Throwable $error) {
            $this->cleanupRolledBackFiles($paths, $bodyCompleted);

            // Never propagate a nested exception or its SQL bindings, request, or ticket data.
            if ($error instanceof ValidationException) {
                throw ValidationException::withMessages(['attachments' => __('Shell/Support/messages.upload_failed')]);
            }
            if ($error instanceof AuthenticationException) {
                throw new AuthenticationException;
            }
            if ($error instanceof AuthorizationException) {
                throw (new AuthorizationException)->withStatus($error->status());
            }
            if ($error instanceof ModelNotFoundException) {
                throw new ModelNotFoundException;
            }
            if ($error instanceof HttpExceptionInterface) {
                throw new HttpException($error->getStatusCode(), '', null, $error->getHeaders());
            }
            throw new RuntimeException('Support ticket creation failed.');
        }
    }

    /** Keep private orphans whenever rollback, database availability, or commit outcome is uncertain. */
    private function cleanupRolledBackFiles(array $paths, bool $bodyCompleted): void
    {
        if ($paths === [] || $bodyCompleted) {
            return;
        }
        try {
            if (DB::transactionLevel() !== 0) {
                return;
            }
            $referenced = DB::table('ticket_attachments')->whereIn('storage_path', $paths)->pluck('storage_path')->all();
            $unreferenced = array_values(array_diff($paths, $referenced));
            if ($unreferenced !== []) {
                Storage::disk('local')->delete($unreferenced);
            }
        } catch (Throwable) {
            // Failed verification or cleanup must never risk losing a committed attachment.
        }
    }

    public function revoke(Request $request, string $id): Ticket
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($id): Ticket {
            $this->access->lock();
            $ticket = $this->access->ticket($id, true);
            Gate::authorize('revoke', $ticket);
            $grant = SupportAccessGrant::query()->where('ticket_id', $ticket->id)->where('granted_by', $ticket->user_id)->lockForUpdate()->first();
            if ($grant && $grant->revoked_at === null && $grant->expires_at->isFuture()) {
                $grant->revoked_at = now();
                $grant->save();
                $this->audit->record(app(TenantContext::class), new AuditEntry('support.access.revoked', 'ticket', $ticket->id,
                    after: ['grant_id' => $grant->id, 'revoked_at' => $grant->revoked_at->toIso8601String()]));
            }

            return $ticket;
        });
    }
}
