<?php

namespace Tests\Feature\Shell\Support;

use App\Shell\Support\Models\Ticket;
use App\Shell\Support\TicketDelivery;
use App\Shell\Support\TicketMail;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;

final class SupportDeliveryTest extends SupportTestCase
{
    public function test_disabled_and_log_transports_receive_no_private_ticket(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        config(['features.E1-12.defaults.recipient' => 'support@example.test']);
        self::assertSame('disabled', $this->ticket()['delivery']['status']);
        config(['features.E1-12.defaults.mail_enabled' => true, 'features.E1-12.defaults.smtp_enabled' => true, 'features.E1-12.defaults.mailer' => 'log']);
        self::assertSame('disabled', $this->ticket()['delivery']['status']);
        Mail::assertNothingSent();
    }

    public function test_fake_mail_without_transport_receipt_is_uncertain_and_body_is_escaped(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $this->enable();
        $result = $this->ticket(['body' => '<script>alert("private")</script> Detailed problem']);
        self::assertSame('uncertain', $result['delivery']['status']);
        self::assertSame('uncertain', $result['ticket']['delivery_status']);
        Mail::assertSent(TicketMail::class, function (TicketMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('support@example.test') && str_contains($html, '&lt;script&gt;') && ! str_contains($html, '<script>');
        });
        Mail::assertSentCount(1);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'support.ticket.created')->count());
    }

    public function test_only_confirmed_receipt_reports_sent_after_commit_and_is_not_repeated(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $this->enable();
        $pending = Mockery::mock(PendingMail::class);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->once()->with('support@example.test')->andReturn($pending);
        $pending->shouldReceive('send')->once()->andReturnUsing(function (TicketMail $mail): SentMessage {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame(1, Ticket::withoutGlobalScopes()->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'support.ticket.created')->count());
            self::assertSame('sending', Ticket::withoutGlobalScopes()->firstOrFail()->delivery_status);
            $message = (new Email)->from('sender@example.test')->to('support@example.test')->subject('Fixture')->text('Fixture');

            return new SentMessage(new SymfonySentMessage($message, Envelope::create($message)));
        });
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $result = $this->ticket();
        self::assertSame('sent', $result['delivery']['status']);
        self::assertSame('sent', $result['ticket']['delivery_status']);
        $ticket = Ticket::withoutGlobalScopes()->findOrFail($result['ticket']['id']);
        app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, $owner->id, source: 'job'), function () use ($ticket): void {
            self::assertSame('sent', app(TicketDelivery::class)->send($ticket)['status']);
        });
    }

    public function test_smtp_exception_is_uncertain_and_never_logged_or_persisted(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $this->enable();
        Log::spy();
        $pending = Mockery::mock(PendingMail::class);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->with('support@example.test')->andReturn($pending);
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP-SENSITIVE-ERROR'));
        Mail::shouldReceive('mailer')->with('smtp')->andReturn($mailer);
        $result = $this->ticket();
        self::assertSame('uncertain', $result['delivery']['status']);
        self::assertStringNotContainsString('SMTP-SENSITIVE-ERROR', json_encode($result));
        self::assertStringNotContainsString('SMTP-SENSITIVE-ERROR', json_encode(DB::table('audit_logs')->get()));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    private function enable(): void
    {
        config(['features.E1-12.defaults.mail_enabled' => true, 'features.E1-12.defaults.smtp_enabled' => true,
            'features.E1-12.defaults.mailer' => 'smtp', 'features.E1-12.defaults.recipient' => 'support@example.test']);
    }
}
