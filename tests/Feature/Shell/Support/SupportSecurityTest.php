<?php

namespace Tests\Feature\Shell\Support;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Support\Models\SupportAccessGrant;
use App\Shell\Support\Models\Ticket;
use App\Shell\Support\Models\TicketAttachment;
use App\Tenancy\TenantContext;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;

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
        $this->get('/help/support')->assertInertia(fn (Assert $page) => $page->where('support.tickets.total', 0));
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
        $this->get('/help/support')->assertInertia(fn (Assert $page) => $page->where('support.tickets.total', 2));
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
}
