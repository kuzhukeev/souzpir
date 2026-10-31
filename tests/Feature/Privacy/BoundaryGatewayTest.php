<?php

namespace Tests\Feature\Privacy;

use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Privacy\ContactBoundary;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class BoundaryGatewayTest extends TestCase
{
    use DatabaseMigrations;

    public function test_obfuscated_input_is_rejected_before_gateway_reservation_or_fake_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$organization, $brand] = $this->cabinet();
        $context = new AiContext($organization, $brand, 'responder', 'seller', 'brand', $brand, 'privacy-before-reserve', ['conversation_content']);
        app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), function () use ($context): void {
            foreach ([
                '"first last"@example.test',
                'user＠example.test',
                'user%40example.test',
                'user at example.test',
                'user at example [dot] test',
                'user at example (dot) test',
                '+7;999;123;45;67',
                '+7:999:123:45:67',
                'user AT example.test',
                'user собака example.ru',
                '+7|999|123|45|67',
                '+7,999,123,45,67',
                "+7\u{00A0}999\u{00A0}123\u{00A0}45\u{00A0}67",
            ] as $contact) {
                $request = new AiRequest([['role' => 'user', 'content' => 'Контакт: '.$contact]], 'google/gemini-2.5-flash', 0.0);
                $rejected = false;
                try {
                    $masked = app(ContactMasker::class)->request($request->toArray());
                    app(ContactBoundary::class)->assertSafe($masked);
                } catch (InvalidArgumentException $e) {
                    $rejected = true;
                    self::assertStringNotContainsString($contact, $e->getMessage());
                }
                // A later gateway error must not count as a privacy rejection.
                if (! $rejected) {
                    app(AiGateway::class)->complete($request, $context);
                }
                self::assertTrue($rejected, 'An unmasked synthetic contact passed both protection layers.');
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('ai_budgets')->count());
            Http::assertNothingSent();
        });
    }

    public function test_masked_ordinary_input_survives_boundary_and_reaches_fake_provider(): void
    {
        [$organization, $brand] = $this->cabinet();
        DB::table('brands')->where('id', $brand)->update([
            'settings' => json_encode(['ai_data_policy' => ['conversation_content' => ['any']]], JSON_THROW_ON_ERROR),
        ]);
        config([
            'runtime.ai_driver' => 'openrouter',
            'features.E0-05.defaults.live_enabled' => true,
            'features.E0-05.defaults.openrouter_key' => 'test-only',
            'features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100],
            'features.E0-05.defaults.reservation_request_limits' => ['google/gemini-2.5-flash' => ['max_input_bytes' => 4096, 'max_output_tokens' => 1000]],
        ]);
        Http::preventStrayRequests();
        Http::fake(['openrouter.ai/*' => Http::response('{"model":"google/gemini-2.5-flash","choices":[{"message":{"content":"ok"}}],"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":0.000004}}', 200)]);
        $request = new AiRequest([['role' => 'user', 'content' => 'Пишите user@example.test или +7 (999) 123-45-67']], 'google/gemini-2.5-flash', 0.0, maxTokens: 100);
        $context = new AiContext($organization, $brand, 'responder', 'seller', 'brand', $brand, 'privacy-allowed', ['conversation_content']);
        app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), function () use ($request, $context): void {
            $masked = app(ContactMasker::class)->request($request->toArray());
            app(ContactBoundary::class)->assertSafe($masked);
            $result = app(AiGateway::class)->complete($request, $context);
            self::assertSame('ok', $result->content);
            self::assertSame(1, DB::table('ai_calls')->count());
        });
        Http::assertSent(function (HttpRequest $http): bool {
            $body = $http->body();

            return ! str_contains($body, 'user@example.test')
                && ! str_contains($body, '999) 123')
                && str_contains($body, '[EMAIL]');
        });
    }

    private function cabinet(): array
    {
        $organization = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $organization, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $organization, 'name' => 'Test', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);

        return [$organization, $brand];
    }
}
