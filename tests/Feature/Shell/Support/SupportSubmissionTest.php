<?php

namespace Tests\Feature\Shell\Support;

use App\Shell\Support\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class SupportSubmissionTest extends SupportTestCase
{
    public function test_replay_after_uncertain_send_returns_same_ticket_without_sending_again(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $this->enableMail();
        $payload = $this->payload();
        $submit = fn () => $this->post('/help/support', [...$payload, 'attachments' => [$this->attachment()]], ['Accept' => 'application/json']);
        $first = $submit()->assertCreated()->assertJsonPath('data.delivery.status', 'uncertain');
        $id = $first->json('data.ticket.id');
        $submit()->assertOk()->assertJsonPath('data.ticket.id', $id)->assertJsonPath('data.delivery.status', 'uncertain');
        $this->post('/help/support', [...$payload, 'submission_key' => strtolower($payload['submission_key']), 'attachments' => [$this->attachment()]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.ticket.id', $id);
        self::assertSame(1, Ticket::withoutGlobalScopes()->count());
        self::assertSame(1, DB::table('ticket_attachments')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'support.ticket.created')->count());
        Mail::assertSentCount(1);

        // An interrupted commit-to-send or send operation must not trigger transport on retry.
        foreach (['pending', 'sending', 'uncertain'] as $status) {
            DB::table('tickets')->where('id', $id)->update(['delivery_status' => $status]);
            $submit()->assertOk()->assertJsonPath('data.ticket.id', $id)->assertJsonPath('data.delivery.status', 'uncertain');
            Mail::assertSentCount(1);
        }
    }

    public function test_key_is_required_and_reuse_with_changed_content_is_rejected(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $this->enableMail();
        $payload = $this->payload();
        $this->post('/help/support', [...$payload, 'attachments' => [$this->attachment()]], ['Accept' => 'application/json'])->assertCreated();
        foreach ([
            ['body' => 'Другое подробное описание ошибки.'],
            ['support_access' => false],
            ['attachments' => [$this->attachment('different.txt')]],
            ['attachments' => [UploadedFile::fake()->createWithContent('details.txt', 'Different file contents.')]],
            ['attachments' => []],
        ] as $change) {
            $files = ['attachments' => [$this->attachment()]];
            $this->post('/help/support', [...$payload, ...$files, ...$change], ['Accept' => 'application/json'])->assertStatus(409);
        }
        $missing = $this->payload();
        unset($missing['submission_key']);
        $this->postJson('/help/support', $missing)->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $this->postJson('/help/support', [...$missing, 'submission_key' => 'bad'])->assertUnprocessable();
        self::assertSame(1, Ticket::withoutGlobalScopes()->count());
        Mail::assertSentCount(1);
    }

    public function test_private_list_is_absent_from_page_but_available_from_no_store_json(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $body = 'Уникальная приватная запись обращения 947348';
        $filename = 'private-947348.txt';
        $this->ticket(['body' => $body, 'attachments' => [$this->attachment($filename)]]);
        $page = $this->get('/help/support')->assertOk();
        $page->assertDontSee($body)->assertDontSee($filename);
        $page->assertInertia(fn ($props) => $props->where('support.tickets.data', []));
        $list = $this->getJson('/help/support')->assertOk()->assertJsonPath('data.tickets.data.0.body', $body)
            ->assertJsonPath('data.tickets.data.0.attachments.0.name', $filename);
        self::assertStringContainsString('no-store', $list->headers->get('Cache-Control'));
        DB::table('users')->where('id', $owner->id)->increment('auth_version');
        $this->getJson('/help/support')->assertUnauthorized();
    }

    public function test_submission_unique_constraint_allows_legacy_rows_and_rejects_racing_duplicate(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $payload = $this->payload();
        $this->postJson('/help/support', $payload)->assertCreated();
        $original = (array) DB::table('tickets')->first();
        $legacy = $original;
        $legacy['id'] = (string) Str::ulid();
        $legacy['submission_key'] = null;
        $legacy['submission_hash'] = null;
        DB::table('tickets')->insert($legacy);
        $duplicate = $original;
        $duplicate['id'] = (string) Str::ulid();
        try {
            DB::table('tickets')->insert($duplicate);
            self::fail('Database must reject simultaneous identical submission keys.');
        } catch (QueryException $error) {
            self::assertSame('23505', $error->errorInfo[0]);
        }
        $duplicate['id'] = (string) Str::ulid();
        $duplicate['submission_hash'] = null;
        try {
            DB::table('tickets')->insert($duplicate);
            self::fail('A submission key without a fingerprint must be rejected.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->errorInfo[0]);
        }
        self::assertSame(2, DB::table('tickets')->count());
    }

    public function test_http_is_allowed_only_for_local_environment_and_direct_loopback_to_dev_host(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $ticket = $this->ticket(['attachments' => [$this->attachment()]])['ticket'];
        $paths = ['/help/support', parse_url($ticket['attachments'][0]['download_url'], PHP_URL_PATH)];
        app('url')->forceScheme('http');
        app('url')->forceRootUrl('http://souzpir.local');
        $this->withServerVariables(['HTTPS' => 'off', 'HTTP_HOST' => 'souzpir.local', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        foreach ($paths as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('/help/support', $this->payload())->assertForbidden();
        $this->postJson('/help/support/'.$ticket['id'].'/access/revoke')->assertForbidden();

        $this->app['env'] = 'local';
        $this->getJson('/help/support')->assertForbidden(); // A forwarded address cannot prove direct local access.
        $this->withServerVariables(['HTTP_HOST' => 'souzpir.local', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.1.48']);
        $this->getJson('/help/support')->assertOk();
        $this->withServerVariables(['HTTP_HOST' => 'souzpir.local', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '192.0.2.10']);
        $this->getJson('/help/support')->assertForbidden();
        $this->withServerVariables(['HTTP_HOST' => 'souzpir.local', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.1.48', 'HTTP_CF_CONNECTING_IP' => '192.0.2.10']);
        $this->getJson('/help/support')->assertForbidden();
        $this->withServerVariables(['HTTP_HOST' => 'souzpir.local', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.0.2.10']);
        $this->getJson('/help/support')->assertForbidden();
        $this->withServerVariables(['HTTP_HOST' => 'souzpir.local', 'REMOTE_ADDR' => '192.0.2.10']);
        $this->getJson('/help/support')->assertForbidden();
        app('url')->forceRootUrl('http://localhost');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost']);
        $this->getJson('/help/support')->assertForbidden();
    }

    public function test_kernel_rejects_spoofed_https_and_allows_only_explicit_proxy_peers(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $this->ticket();
        app('url')->forceScheme('http');
        $remote = ['HTTPS' => 'off', 'REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20'];
        $url = 'http://cabinet.example.test/help/support';

        try {
            $this->withServerVariables($remote)->withHeader('X-Forwarded-Proto', 'https');
            config(['trustedproxy.proxies' => null]);
            // Laravel's Forge host shortcut can populate globally trusted proxies.
            $this->getJson('http://x.on-forge.com/help/support')->assertForbidden();
            $this->postJson('http://x.on-forge.com/help/support', $this->payload())->assertForbidden();

            foreach ([['0.0.0.0/0'], ['::/0'], ['*'], ['198.51.100.10', 'invalid'], ['198.51.100.10', '0.0.0.0/0']] as $proxies) {
                config(['trustedproxy.proxies' => $proxies]);
                $this->getJson($url)->assertForbidden();
            }

            $this->withServerVariables([...$remote, 'REMOTE_ADDR' => '2001:db8::10']);
            config(['trustedproxy.proxies' => ['::/0']]);
            $this->getJson($url)->assertForbidden();
            config(['trustedproxy.proxies' => ['2001:db8::10']]);
            $this->getJson($url)->assertOk();
            $this->withServerVariables($remote);

            config(['trustedproxy.proxies' => ['198.51.100.10']]);
            $this->getJson($url)->assertOk();
            Request::setTrustedProxies(['0.0.0.0/0'], Request::HEADER_X_FORWARDED_PROTO);
            $this->withServerVariables([...$remote, 'REMOTE_ADDR' => '198.51.100.11'])->getJson($url)->assertForbidden();
            $this->withServerVariables($remote)->withHeader('X-Forwarded-Proto', 'https,http')->getJson($url)->assertForbidden();
            $this->withHeader('X-Forwarded-Proto', 'https')->withHeader('Forwarded', 'proto=http')->getJson($url)->assertForbidden();

            $this->flushHeaders()->withServerVariables($remote);
            config(['trustedproxy.proxies' => ['0.0.0.0/0']]);
            $this->getJson('https://cabinet.example.test/help/support')->assertOk();
        } finally {
            config(['trustedproxy.proxies' => null]);
            Request::setTrustedProxies([], 0);
        }
    }

    private function enableMail(): void
    {
        config(['features.E1-12.defaults.mail_enabled' => true, 'features.E1-12.defaults.smtp_enabled' => true,
            'features.E1-12.defaults.mailer' => 'smtp', 'features.E1-12.defaults.recipient' => 'support@example.test']);
    }
}
