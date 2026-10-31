<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\EmailChanges;
use App\Shell\Profile\EmailChangeSender;
use App\Shell\Profile\MailEmailChangeSender;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Tenancy\TenancyTestCase;

final class EmailChangeTest extends TenancyTestCase
{
    private function enable(): void
    {
        $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1']);
        config(['features.E1-08.defaults.email_change_enabled' => true]);
        $this->app->instance(EmailChangeSender::class, new class implements EmailChangeSender
        {
            public function send(string $email, string $confirmationUrl): bool
            {
                return true;
            }
        });
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function link(string $userId): string
    {
        $row = DB::table('profile_email_changes')->where('user_id', $userId)->first();

        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('profile.email.confirm', CarbonImmutable::parse($row->expires_at), ['change' => $row->id, 'token' => Crypt::decryptString($row->encrypted_token)], absolute: false);
    }

    public function test_old_verified_email_stays_until_signed_post_and_confirm_is_single_use(): void
    {
        $this->enable();
        [$user,,$brand] = $this->cabinet();
        $old = $user->email;
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/email', ['email' => 'NEW@example.test', 'current_password' => 'test-only-password'])->assertRedirect('/profile');
        $this->assertSame($old, $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $url = $this->link($user->id);
        $this->get($url)->assertOk()->assertViewHas('page', fn ($p) => $p['props']['emailConfirmationUrl'] === parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY) && $p['encryptHistory'] === true);
        $this->assertSame($old, $user->fresh()->email);
        $this->post($url)->assertRedirect('/profile');
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertSame(1, $user->fresh()->auth_version);
        $this->assertDatabaseHas('profile_email_changes', ['user_id' => $user->id, 'status' => 'confirmed', 'target_email' => null, 'token_hash' => null]);
        $this->post($url)->assertNotFound();
        $this->get('/profile')->assertOk()->assertViewHas('page', fn ($p) => $p['clearHistory'] === true);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'profile.email.changed']);
    }

    public function test_disabled_transport_and_wrong_password_do_not_change_or_reserve_email(): void
    {
        [$user,,$brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->post('/profile/email', ['email' => 'new@example.test', 'current_password' => 'test-only-password'])->assertSessionHasErrors('email');
        $this->enable();
        $this->post('/profile/email', ['email' => 'new@example.test', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertDatabaseCount('profile_email_changes', 0);
    }

    public function test_confirmation_rejects_foreign_user_tampering_expiry_and_revoked_version(): void
    {
        $this->enable();
        [$user,$org,$brand] = $this->cabinet();
        [$other] = $this->member($org, 'admin');
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/email', ['email' => 'new@example.test', 'current_password' => 'test-only-password'])->assertRedirect();
        $url = $this->link($user->id);
        $this->actingAs($other)->withSession(['brand_id' => $brand->id])->get($url)->assertNotFound();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->get($url.'x')->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();
        $user->forceFill(['auth_version' => 5])->save();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->get($url)->assertNotFound();
    }

    public function test_cancel_and_reissue_invalidate_old_link_and_clear_secret_payload(): void
    {
        $this->enable();
        [$user,,$brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->post('/profile/email', ['email' => 'first@example.test', 'current_password' => 'test-only-password'])->assertRedirect();
        $first = $this->link($user->id);
        $this->post('/profile/email', ['email' => 'second@example.test', 'current_password' => 'test-only-password'])->assertRedirect();
        $second = $this->link($user->id);
        $this->get($first)->assertNotFound();
        $this->delete('/profile/email')->assertRedirect('/profile');
        $this->get('/profile')->assertOk()->assertViewHas('page', fn ($p) => $p['clearHistory'] === true);
        $this->get($second)->assertNotFound();
        $this->assertDatabaseHas('profile_email_changes', ['user_id' => $user->id, 'status' => 'cancelled', 'target_email' => null, 'target_fingerprint' => null, 'encrypted_token' => null]);
    }

    public function test_pending_address_cannot_be_reserved_by_another_user(): void
    {
        $this->enable();
        [$user,$org,$brand] = $this->cabinet();
        [$other] = $this->member($org, 'admin');
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/email', ['email' => 'reserved@example.test', 'current_password' => 'test-only-password'])->assertRedirect();
        $this->actingAs($other)->withSession(['brand_id' => $brand->id])->post('/profile/email', ['email' => 'RESERVED@example.test', 'current_password' => 'test-only-password'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('profile_email_changes', 1);
        $row = DB::table('profile_email_changes')->first();
        $this->assertNotSame('reserved@example.test', $row->target_email);
        $logs = json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString('reserved@example.test', $logs);
        $this->assertStringNotContainsString(Crypt::decryptString($row->encrypted_token), $logs);
    }

    public function test_confirmation_props_are_origin_relative_even_with_another_host(): void
    {
        $this->enable();
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/email', ['email' => 'new@example.test', 'current_password' => 'test-only-password'])->assertRedirect();
        $url = $this->link($user->id);
        $relative = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $response = $this->get('https://untrusted.example'.$relative)->assertOk();
        $page = $response->viewData('page');
        $this->assertSame($relative, $page['props']['emailConfirmationUrl']);
        $this->assertStringNotContainsString('untrusted.example', $page['props']['emailConfirmationUrl']);
        $this->assertTrue($page['encryptHistory']);
    }

    public function test_unexpected_database_error_does_not_expose_email_bindings(): void
    {
        $this->enable();
        [$user, , $brand] = $this->cabinet();
        $marker = 'synthetic-private-address@example.test';
        $audit = $this->createStub(AuditWriter::class);
        $audit->method('record')->willThrowException(new QueryException('pgsql', 'insert into synthetic values (?)', [$marker], new \RuntimeException('synthetic failure')));
        $this->app->instance(AuditWriter::class, $audit);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/email', ['email' => $marker, 'current_password' => 'test-only-password']);
            self::fail('Expected a sanitized exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('Email change processing failed.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString($marker, $e->getTraceAsString());
        }
        $this->assertDatabaseCount('profile_email_changes', 0);
    }

    public function test_real_mail_sender_is_disabled_even_if_feature_flag_is_enabled(): void
    {
        config(['features.E1-08.defaults.email_change_enabled' => true, 'app.url' => 'https://cabinet.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.example.test']);
        $this->app->instance(EmailChangeSender::class, new MailEmailChangeSender);
        self::assertFalse(app(EmailChanges::class)->available());
    }

    public function test_email_commit_database_error_redacts_new_address_and_token(): void
    {
        $this->enable();
        [$user, , $brand] = $this->cabinet();
        $marker = 'synthetic-commit-address@example.test';
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/email', ['email' => $marker, 'current_password' => 'test-only-password'])->assertRedirect();
        $url = $this->link($user->id);
        $token = Crypt::decryptString(DB::table('profile_email_changes')->where('user_id', $user->id)->value('encrypted_token'));
        $audit = $this->createStub(AuditWriter::class);
        $audit->method('record')->willThrowException(new QueryException('pgsql', 'update synthetic set email = ?', [$marker], new \RuntimeException('synthetic failure')));
        $this->app->instance(AuditWriter::class, $audit);
        $this->withoutExceptionHandling();
        try {
            $this->post($url);
            self::fail('Expected a sanitized exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('Email confirmation processing failed.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString($marker, $e->getTraceAsString());
            // The test client's own post($url) frame contains the synthetic URI.
            // Inspect application/middleware frames separately; production email remains disabled.
            $runtimeTrace = explode('Illuminate\\Foundation\\Testing\\Concerns\\MakesHttpRequests.php', $e->getTraceAsString(), 2)[0];
            self::assertStringNotContainsString($token, $runtimeTrace);
        }
        self::assertNotSame($marker, $user->fresh()->email);
    }
}
