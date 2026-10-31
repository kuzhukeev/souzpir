<?php

namespace Tests\Feature\Shell\Support;

use App\Shell\Support\Models\SupportAccessGrant;
use App\Shell\Support\Models\Ticket;
use App\Shell\Support\Models\TicketAttachment;
use App\Shell\Support\SupportConsent;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

final class SupportFlowTest extends SupportTestCase
{
    public function test_ticket_private_attachment_consent_and_sanitized_audit_are_persisted(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $result = $this->ticket(['attachments' => [$this->attachment()], 'user_id' => 'untrusted', 'organization_id' => 'untrusted', 'status' => 'closed']);
        $ticket = Ticket::withoutGlobalScopes()->findOrFail($result['ticket']['id']);
        self::assertSame($owner->id, $ticket->user_id);
        self::assertSame($org->id, $ticket->organization_id);
        self::assertSame('open', $ticket->status);
        self::assertSame('not_configured', $result['delivery']['status']);
        self::assertSame('not_configured', $ticket->delivery_status);
        $grant = SupportAccessGrant::withoutGlobalScopes()->where('ticket_id', $ticket->id)->firstOrFail();
        self::assertSame(72.0, $grant->granted_at->diffInHours($grant->expires_at));
        self::assertSame('active', $result['ticket']['access']['status']);
        $file = TicketAttachment::withoutGlobalScopes()->where('ticket_id', $ticket->id)->firstOrFail();
        Storage::disk('local')->assertExists($file->storage_path);
        $download = $this->get($result['ticket']['attachments'][0]['download_url']);
        $download->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        self::assertSame("Приватный текст вложения.\n", $download->getContent());
        $audit = DB::table('audit_logs')->where('action', 'support.ticket.created')->first();
        self::assertNotNull($audit);
        self::assertSame($owner->id, $audit->actor_id);
        self::assertSame($org->id, $audit->organization_id);
        foreach ([$ticket->body, $file->storage_path, $file->name, $owner->email] as $private) {
            self::assertStringNotContainsString($private, $audit->after);
        }
        $this->get('/help/support')->assertInertia(fn (Assert $page) => $page->component('Shell/Support/Tickets')
            ->where('support.tickets.data', [])->where('support.access.entry_available', false));
        $this->getJson('/help/support')->assertOk()->assertJsonPath('data.tickets.total', 1)
            ->assertJsonMissingPath('data.tickets.data.0.attachments.0.storage_path')->assertJsonMissingPath('data.tickets.data.0.attachments.0.sha256');
        Mail::assertNothingSent();
    }

    public function test_consent_expires_at_exact_72_hours_and_revoke_is_immediate_and_idempotent(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $first = $this->ticket()['ticket'];
        $grant = SupportAccessGrant::withoutGlobalScopes()->findOrFail($first['access']['id']);
        $ticket = Ticket::withoutGlobalScopes()->findOrFail($first['id']);
        $this->travelTo($grant->expires_at->subSecond());
        app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, $owner->id, source: 'job'), function () use ($ticket): void {
            self::assertTrue(app(SupportConsent::class)->hasCurrentConsent($ticket));
        });
        $this->travelTo($grant->expires_at);
        app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, $owner->id, source: 'job'), function () use ($ticket): void {
            self::assertFalse(app(SupportConsent::class)->hasCurrentConsent($ticket));
        });
        $this->signIn($owner, $brand);
        $this->getJson('/help/support')->assertOk()->assertJsonPath('data.tickets.data.0.access.status', 'expired')->assertJsonPath('data.tickets.data.0.access.can_revoke', false);
        $second = $this->ticket()['ticket'];
        $this->postJson('/help/support/'.$second['id'].'/access/revoke')->assertOk()->assertJsonPath('data.ticket.access.status', 'revoked');
        $this->postJson('/help/support/'.$second['id'].'/access/revoke')->assertOk();
        self::assertSame(1, DB::table('audit_logs')->where('action', 'support.access.revoked')->count());
        $this->getJson('/help/support')->assertOk()->assertJsonPath('data.tickets.data.0.access.can_revoke', false);
    }

    public function test_all_active_roles_can_submit_their_own_tickets(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        foreach ([['admin', false], ['member', false], ['member', true], ['viewer', false]] as [$role, $manager]) {
            [$user] = $this->member($org, $role, [$brand->id], $manager);
            $this->signIn($user, $brand);
            $this->get('/help/support')->assertInertia(fn (Assert $page) => $page->where('support.can_submit', true));
            $this->postJson('/help/support', $this->payload())->assertCreated();
        }
        self::assertSame(4, Ticket::withoutGlobalScopes()->count());
        $this->signIn($owner, $brand);
        $this->getJson('/help/support')->assertOk()->assertJsonPath('data.tickets.total', 0);
    }

    public function test_attachment_validation_and_missing_or_tampered_private_file_fail_closed(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $this->post('/help/support', [...$this->payload(), 'attachments' => [$this->attachment('payload.svg')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson('/help/support', [...$this->payload(), 'subject' => 'invalid', 'body' => 'short'])->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'Описание должно содержать минимум 10 символов.');
        self::assertSame(0, Ticket::withoutGlobalScopes()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
        $created = $this->ticket(['attachments' => [$this->attachment()]])['ticket'];
        $file = TicketAttachment::withoutGlobalScopes()->where('ticket_id', $created['id'])->firstOrFail();
        Storage::disk('local')->put($file->storage_path, 'corrupted');
        $this->get($created['attachments'][0]['download_url'])->assertStatus(503);
        Storage::disk('local')->delete($file->storage_path);
        $this->get($created['attachments'][0]['download_url'])->assertNotFound();
    }
}
