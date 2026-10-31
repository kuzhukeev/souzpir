<?php

namespace Tests\Feature\Shell\Support;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Support\Models\SupportAccessGrant;
use App\Shell\Support\Models\Ticket;
use App\Shell\Support\Models\TicketAttachment;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Throwable;

final class SupportSecurityTest extends SupportTestCase
{
    public function test_other_user_and_foreign_organization_tickets_and_attachment_swaps_are_hidden(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id, $other->id]);
        $this->signIn($owner, $brand);
        $first = $this->ticket(['attachments' => [$this->attachment('пояснение.txt')]])['ticket'];
        $second = $this->ticket(['attachments' => [$this->attachment()]])['ticket'];
        $this->get('/help/support/'.$first['id'].'/attachments/'.$second['attachments'][0]['id'])->assertNotFound();
        $this->signIn($owner, $other); // Tickets belong to the organization, not the selected brand.
        $this->get($first['attachments'][0]['download_url'])->assertOk();
        $this->signIn($admin, $brand);
        $this->getJson('/help/support')->assertOk()->assertJsonPath('data.tickets.total', 0);
        $this->get($first['attachments'][0]['download_url'])->assertNotFound();
        $this->postJson('/help/support/'.$first['id'].'/access/revoke')->assertNotFound();
        $adminTicket = $this->ticket(['attachments' => [$this->attachment()]])['ticket'];
        [$foreignOwner, , $foreignBrand] = $this->cabinet();
        $this->signIn($foreignOwner, $foreignBrand);
        $foreign = $this->ticket(['attachments' => [$this->attachment()]])['ticket'];
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $this->signIn($owner, $brand);
        foreach ([$adminTicket, $foreign] as $hidden) {
            $this->get($hidden['attachments'][0]['download_url'])->assertNotFound();
            $this->postJson('/help/support/'.$hidden['id'].'/access/revoke')->assertNotFound();
        }
        $this->getJson('/help/support')->assertOk()->assertJsonPath('data.tickets.total', 2);
        self::assertSame(0, DB::table('audit_logs')->where('action', 'support.access.revoked')->count());
        $file = TicketAttachment::withoutGlobalScopes()->findOrFail($first['attachments'][0]['id']);
        DB::table('ticket_attachments')->where('id', $file->id)->update(['storage_path' => 'private/unrelated.bin']);
        $this->get($first['attachments'][0]['download_url'])->assertNotFound();
    }

    public function test_stale_session_cannot_create_revoke_or_download(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $ticket = $this->ticket(['attachments' => [$this->attachment()]])['ticket'];
        DB::table('users')->where('id', $owner->id)->increment('auth_version');
        $this->getJson($ticket['attachments'][0]['download_url'])->assertUnauthorized();
        $this->postJson('/help/support/'.$ticket['id'].'/access/revoke')->assertUnauthorized();
        $this->postJson('/help/support', $this->payload())->assertUnauthorized();
        self::assertSame(1, Ticket::withoutGlobalScopes()->count());
        self::assertNull(SupportAccessGrant::withoutGlobalScopes()->findOrFail($ticket['access']['id'])->revoked_at);
        self::assertSame(1, count(Storage::disk('local')->allFiles()));
        Mail::assertNothingSent();
    }

    public function test_authority_revoked_after_initial_policy_is_rechecked_before_any_storage_write(): void
    {
        [, $org, $brand] = $this->cabinet();
        foreach (['version', 'lease', 'membership'] as $mode) {
            [$actor, $member] = $this->member($org, 'member', [$brand->id]);
            $this->signIn($actor, $brand);
            $pending = true;
            Gate::after(function ($user, string $ability) use (&$pending, $mode, $actor, $member): void {
                if (! $pending || $ability !== 'create' || $user->id !== $actor->id) {
                    return;
                }
                $pending = false;
                if ($mode === 'version') {
                    DB::table('users')->where('id', $actor->id)->increment('auth_version');
                } elseif ($mode === 'lease') {
                    DB::table('auth_session_registry')->where('user_id', $actor->id)->update(['revoked_at' => now()]);
                } else {
                    DB::table('memberships')->where('id', $member->id)->update(['status' => 'disabled']);
                }
            });
            $response = $this->post('/help/support', [...$this->payload(), 'attachments' => [$this->attachment()]], ['Accept' => 'application/json']);
            self::assertSame($mode === 'membership' ? 404 : 401, $response->status());
        }
        self::assertSame(0, Ticket::withoutGlobalScopes()->count());
        self::assertSame(0, SupportAccessGrant::withoutGlobalScopes()->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'support.ticket.created')->count());
        self::assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public function test_failed_audit_rolls_back_ticket_grant_attachment_and_removes_private_bytes(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        app()->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                throw new RuntimeException('Audit rollback fixture.');
            }
        });
        $this->post('/help/support', [...$this->payload(), 'attachments' => [$this->attachment()]], ['Accept' => 'application/json'])->assertStatus(500);
        self::assertSame(0, Ticket::withoutGlobalScopes()->count());
        self::assertSame(0, SupportAccessGrant::withoutGlobalScopes()->count());
        self::assertSame(0, TicketAttachment::withoutGlobalScopes()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public function test_partial_failed_file_write_is_removed_and_no_ticket_is_persisted(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $disk = Storage::disk('local');
        $failed = Mockery::mock(FilesystemAdapter::class);
        $failed->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $name) use ($disk): bool {
            $disk->put($directory.'/'.$name, 'partial bytes');

            return false;
        });
        $failed->shouldReceive('delete')->once()->andReturnUsing(fn ($paths) => $disk->delete($paths));
        Storage::shouldReceive('disk')->with('local')->andReturn($failed);
        $this->post('/help/support', [...$this->payload(), 'attachments' => [$this->attachment()]], ['Accept' => 'application/json'])->assertUnprocessable();
        self::assertSame(0, Ticket::withoutGlobalScopes()->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'support.ticket.created')->count());
        self::assertSame([], $disk->allFiles());
        Mail::assertNothingSent();
    }

    public function test_caller_owned_outer_transaction_is_rejected_before_files_or_mail(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        DB::beginTransaction();
        try {
            $this->post('/help/support', [...$this->payload(), 'attachments' => [$this->attachment()]], ['Accept' => 'application/json'])->assertStatus(500);
            self::assertSame(0, Ticket::withoutGlobalScopes()->count());
            self::assertSame(0, TicketAttachment::withoutGlobalScopes()->count());
            self::assertSame([], Storage::disk('local')->allFiles());
            Mail::assertNothingSent();
        } finally {
            DB::rollBack();
        }
    }

    public function test_uncertain_post_commit_failure_never_removes_committed_private_attachment(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        app()->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                DB::afterCommit(fn () => throw new RuntimeException('Simulated lost commit acknowledgement.'));
            }
        });
        $this->post('/help/support', [...$this->payload(), 'attachments' => [$this->attachment()]], ['Accept' => 'application/json'])->assertStatus(500);
        self::assertSame(1, Ticket::withoutGlobalScopes()->count());
        $attachment = TicketAttachment::withoutGlobalScopes()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->storage_path);
        Mail::assertNothingSent();
    }

    public function test_database_failure_during_rollback_verification_keeps_private_orphan(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $verificationStarted = false;
        $dbUnavailable = false;
        DB::listen(function (QueryExecuted $query) use (&$verificationStarted, &$dbUnavailable): void {
            if ($dbUnavailable && str_contains($query->sql, 'ticket_attachments')) {
                $verificationStarted = true;
                throw new RuntimeException('Simulated database unavailable.');
            }
        });
        app()->instance(AuditWriter::class, new class($dbUnavailable) implements AuditWriter
        {
            private mixed $unavailable;

            public function __construct(bool &$unavailable)
            {
                $this->unavailable = &$unavailable;
            }

            public function record(TenantContext $context, AuditEntry $entry): void
            {
                $this->unavailable = true;
                throw new RuntimeException('Audit rollback fixture.');
            }
        });
        $this->post('/help/support', [...$this->payload(), 'attachments' => [$this->attachment()]], ['Accept' => 'application/json'])->assertStatus(500);
        $dbUnavailable = false;
        self::assertTrue($verificationStarted);
        self::assertSame(0, Ticket::withoutGlobalScopes()->count());
        self::assertCount(1, Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public function test_unexpected_audit_failure_strips_request_nested_exceptions_and_sql_bindings_from_trace(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $marker = 'SYNTHETIC_SUPPORT_PRIVATE_MARKER_123456789';
        app()->instance(AuditWriter::class, new class($marker) implements AuditWriter
        {
            public function __construct(private string $marker) {}

            public function record(TenantContext $context, AuditEntry $entry): void
            {
                throw new QueryException('test', 'select ?', [$this->marker], new RuntimeException($this->marker));
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/help/support', [...$this->payload(), 'body' => $marker, 'attachments' => [$this->attachment($marker.'.txt')]], ['Accept' => 'application/json']);
            self::fail('An error was expected.');
        } catch (RuntimeException $error) {
            self::assertSame('Support request failed.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($marker, $error->getTraceAsString());
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (! str_starts_with($class, 'App\\') && ! str_starts_with($class, 'Illuminate\\Routing\\') && ! str_starts_with($class, 'Illuminate\\Pipeline\\')) {
                    continue;
                }
                self::assertStringNotContainsString($marker, json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR));
                foreach ($frame['args'] ?? [] as $arg) {
                    if ($arg instanceof Request) {
                        self::assertStringNotContainsString($marker, json_encode($arg->all(), JSON_PARTIAL_OUTPUT_ON_ERROR));
                        self::assertStringNotContainsString($marker, $arg->getContent());
                    }
                }
            }
        }
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_viewer_cannot_revoke_another_users_consent(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $ticket = $this->ticket()['ticket'];
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->signIn($viewer, $brand);
        $this->postJson('/help/support/'.$ticket['id'].'/access/revoke')->assertNotFound();
        self::assertNull(SupportAccessGrant::withoutGlobalScopes()->findOrFail($ticket['access']['id'])->revoked_at);
    }

    public function test_validation_failure_retains_messages_but_does_not_retain_payload_in_exception(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $marker = 'SYNTHETIC_VALIDATION_PRIVATE_MARKER_123456';
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/help/support', [...$this->payload(), 'body' => 'short', 'subject' => $marker]);
            self::fail('A validation error was expected.');
        } catch (ValidationException $error) {
            self::assertSame('Описание должно содержать минимум 10 символов.', $error->errors()['body'][0]);
            self::assertSafeSupportException($error, $marker);
        }
    }

    public function test_upload_exception_is_sanitized(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $marker = 'SYNTHETIC_UPLOAD_PRIVATE_MARKER_123456';
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new QueryException('test', 'select ?', [$marker], new RuntimeException($marker)));
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->withoutExceptionHandling();
        try {
            $this->post('/help/support', [...$this->payload(), 'body' => $marker, 'attachments' => [$this->attachment()]], ['Accept' => 'application/json']);
            self::fail('An upload error was expected.');
        } catch (RuntimeException $error) {
            self::assertSame('Support request failed.', $error->getMessage());
            self::assertSafeSupportException($error, $marker);
        }
    }

    public function test_post_commit_delivery_exception_does_not_log_private_request_or_sql(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $marker = 'SYNTHETIC_DELIVERY_PRIVATE_MARKER_123456';
        $reported = [];
        Log::listen(function (MessageLogged $event) use (&$reported): void {
            $reported[] = $event;
        });
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail, $marker): void {
            if ($fail && str_starts_with(ltrim(strtolower($query->sql)), 'update ') && str_contains($query->sql, 'delivery_status')) {
                $fail = false;
                throw new QueryException('test', 'select ?', [$marker], new RuntimeException($marker));
            }
        });
        $this->post('/help/support', [...$this->payload(), 'body' => $marker, 'attachments' => [$this->attachment()]], ['Accept' => 'application/json'])->assertStatus(500);
        self::assertFalse($fail);
        self::assertSame(1, Ticket::withoutGlobalScopes()->count());
        $attachment = TicketAttachment::withoutGlobalScopes()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->storage_path);
        self::assertNotEmpty($reported);
        foreach ($reported as $event) {
            self::assertStringNotContainsString($marker, (string) $event->message);
            if (($event->context['exception'] ?? null) instanceof Throwable) {
                self::assertSafeSupportException($event->context['exception'], $marker);
            }
        }
        Mail::assertNothingSent();
    }

    public function test_delivery_validation_exception_does_not_publish_its_private_message(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $marker = 'SYNTHETIC_DELIVERY_VALIDATION_PRIVATE_MARKER_123456';
        DB::listen(function (QueryExecuted $query) use ($marker): void {
            if (str_starts_with(ltrim(strtolower($query->sql)), 'update ') && str_contains($query->sql, 'delivery_status')) {
                throw ValidationException::withMessages(['body' => $marker]);
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/help/support', [...$this->payload(), 'body' => $marker]);
            self::fail('An error was expected.');
        } catch (RuntimeException $error) {
            self::assertSame('Support request failed.', $error->getMessage());
            self::assertSafeSupportException($error, $marker);
        }
    }

    public function test_sanitized_authorization_exception_preserves_not_found_status(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        Gate::before(fn ($user, string $ability) => $ability === 'create' ? Response::denyAsNotFound() : null);
        $this->postJson('/help/support', $this->payload())->assertNotFound();
        self::assertSame(0, Ticket::withoutGlobalScopes()->count());
    }

    private static function assertSafeSupportException(Throwable $error, string $marker): void
    {
        self::assertNull($error->getPrevious());
        self::assertStringNotContainsString($marker, $error->getMessage());
        // Laravel's in-process test client itself holds the synthetic payload.
        // It does not exist on a real HTTP server stack; inspect application frames above it.
        $trace = explode('vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Testing\\', $error->getTraceAsString(), 2)[0];
        self::assertStringNotContainsString($marker, $trace);
        foreach ($error->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            if (! str_starts_with($class, 'App\\') && ! str_starts_with($class, 'Illuminate\\Routing\\') && ! str_starts_with($class, 'Illuminate\\Pipeline\\')) {
                continue;
            }
            self::assertStringNotContainsString($marker, json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR));
            foreach ($frame['args'] ?? [] as $arg) {
                if ($arg instanceof Request) {
                    self::assertStringNotContainsString($marker, json_encode($arg->all(), JSON_PARTIAL_OUTPUT_ON_ERROR));
                    self::assertStringNotContainsString($marker, $arg->getContent());
                }
            }
        }
    }
}
