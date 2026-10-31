<?php

namespace Tests\Feature\Vision\Evidence;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Models\Brand;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Evidence\ConfirmationValidator;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\Evidence\EvidenceEvaluator;
use App\Vision\Evidence\EvidenceValidator;
use App\Vision\Evidence\SourceExtractor;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EvidenceTestGate implements KnowledgeGate
{
    public bool $allowed = true;

    public int $checks = 0;

    public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void
    {
        $this->checks++;
        if (! $this->allowed) {
            throw new \LogicException('denied');
        }
    }
}

final class EvidenceTestGateway implements AiGateway
{
    public int $calls = 0;

    public ?\Closure $after = null;

    public array $result = [];

    public array $responses = [];

    public function complete(AiRequest $request, AiContext $context): AiResult
    {
        $this->calls++;
        if ($this->after) {
            ($this->after)();
        }

        return new AiResult(json_encode($this->responses === [] ? $this->result : array_shift($this->responses), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [], [], $request->model, 0, 0, (string) Str::ulid());
    }
}

final class EvidenceEvaluatorTest extends TestCase
{
    use DatabaseMigrations;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'evidence_test_'.strtolower(Str::random(12));
        DB::statement('CREATE SCHEMA '.$this->schema);
        DB::statement('SET search_path TO '.$this->schema.', public');
        DB::statement('CREATE TABLE measurements (id varchar(26) primary key, organization_id varchar(26), brand_id varchar(26), profile_snapshot jsonb, profile_checksum varchar(64), configuration jsonb)');
        DB::statement('CREATE TABLE measurement_answers (id varchar(26) primary key, organization_id varchar(26), brand_id varchar(26), measurement_id varchar(26), status varchar(24), protocol_valid boolean, answer_text text)');
        config(['runtime.ai_driver' => 'fake']);
        Http::fake();
    }

    protected function tearDown(): void
    {
        DB::statement('SET search_path TO public');
        DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        parent::tearDown();
    }

    public function test_only_stored_tenant_answer_and_frozen_profile_can_produce_confirmed_fact(): void
    {
        $org = Organization::create(['name' => 'Test']);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => Brand::create(['name' => 'Test', 'theme' => 'neutral']));
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $gate = new EvidenceTestGate;
        $gateway = new EvidenceTestGateway;
        $service = new EvidenceEvaluator($gateway, $gate, app(EvidenceValidator::class), app(ConfirmationValidator::class), app(SourceExtractor::class));
        $profile = ['name' => 'СтальПлюс', 'aliases' => ['СтальПлюс'], 'facts' => []];
        $configuration = ['parser' => [...$service->artifacts(), 'model' => 'google/gemini-2.5-flash', 'temperature' => 0, 'max_tokens' => 1000]];
        $measurement = (string) Str::ulid();
        $answer = (string) Str::ulid();
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile),
            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR)]);
        DB::table('measurement_answers')->insert(['id' => $answer, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'measurement_id' => $measurement, 'status' => 'ok', 'protocol_valid' => true, 'answer_text' => 'Рекомендую СтальПлюс.']);
        $gateway->result = ['brand_mentioned' => true, 'brand_position' => 1, 'brand_recommended' => true,
            'mention_quote' => 'Рекомендую СтальПлюс', 'companies' => [['name' => 'СтальПлюс', 'position' => 1, 'role' => 'supplier', 'is_brand' => true]],
            'tone' => 'positive', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
        $evaluate = fn () => app(TenantContextRunner::class)->run($tenant, fn () => $service->evaluate($tenant, $answer));
        self::assertTrue(Schema::hasTable('measurements'));
        self::assertTrue(Schema::hasTable('measurement_answers'));
        self::assertSame('confirmed', $evaluate()['status']);
        self::assertSame(2, $gate->checks);
        self::assertSame(1, $gateway->calls);

        $badConfiguration = $configuration;
        $badConfiguration['parser']['schema'] = str_repeat('0', 64);
        DB::table('measurements')->where('id', $measurement)->update(['configuration' => json_encode($badConfiguration, JSON_THROW_ON_ERROR)]);
        self::assertSame('unknown', $evaluate()['status']);
        DB::table('measurements')->where('id', $measurement)->update(['configuration' => json_encode($configuration, JSON_THROW_ON_ERROR)]);
        DB::table('measurement_answers')->where('id', $answer)->update(['protocol_valid' => false]);
        self::assertSame('unknown', $evaluate()['status']);
        DB::table('measurement_answers')->where('id', $answer)->update(['protocol_valid' => true]);
        config(['runtime.ai_driver' => 'openrouter']);
        self::assertSame('unknown', $evaluate()['status']);
        config(['runtime.ai_driver' => 'fake']);
        self::assertSame(1, $gateway->calls);

        DB::table('measurements')->where('id', $measurement)->update(['profile_checksum' => str_repeat('0', 64)]);
        self::assertSame('unknown', $evaluate()['status']);
        self::assertSame(1, $gateway->calls);
        DB::table('measurements')->where('id', $measurement)->update(['profile_checksum' => CanonicalJson::hash($profile)]);
        $gate->allowed = false;
        self::assertSame('unknown', $evaluate()['status']);
        self::assertSame(1, $gateway->calls);
        $gate->allowed = true;
        $gateway->after = fn () => DB::table('measurement_answers')->where('id', $answer)->update(['answer_text' => 'Изменено']);
        self::assertSame('unknown', $evaluate()['status']);
        self::assertSame(2, $gateway->calls);
        $gateway->after = null;
        $profile = ['name' => 'Торты', 'aliases' => ['Торты'], 'category' => 'торты', 'facts' => [
            ['topic' => 'address', 'value' => 'Ленина 15', 'verifiable' => true],
            ['topic' => 'warranty', 'value' => 'гарантия 3 дня', 'verifiable' => true],
        ]];
        $text = 'Торты: Ленина 15, гарантия 3 дня.';
        DB::table('measurements')->where('id', $measurement)->update([
            'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile),
        ]);
        DB::table('measurement_answers')->where('id', $answer)->update(['answer_text' => $text]);
        $candidate = ['brand_mentioned' => true, 'brand_position' => 1, 'brand_recommended' => false,
            'mention_quote' => $text, 'companies' => [['name' => 'Торты', 'position' => 1, 'role' => 'supplier', 'is_brand' => true]],
            'tone' => 'neutral', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
        $confirmation = ['decision' => 'confirmed', 'evidence' => $text, 'matched_facts' => ['Ленина 15', 'гарантия 3 дня']];
        $gateway->responses = [$candidate, $confirmation];
        self::assertSame('confirmed', $evaluate()['status']);
        $confirmation['evidence'] = 'Торты: Ленина 15, гарантия 4 дня.';
        $gateway->responses = [$candidate, $confirmation];
        self::assertSame('unknown', $evaluate()['status']);
        $profile = ['name' => 'СтальПлюс', 'aliases' => ['СтальПлюс'], 'facts' => []];
        DB::table('measurements')->where('id', $measurement)->update([
            'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile),
        ]);
        DB::table('measurement_answers')->where('id', $answer)->update(['answer_text' => 'Рекомендую СтальПлюс.']);
        $candidate = ['brand_mentioned' => true, 'brand_position' => 1, 'brand_recommended' => true,
            'mention_quote' => 'Рекомендую СтальПлюс', 'companies' => [['name' => 'СтальПлюс', 'position' => 1, 'role' => 'supplier', 'is_brand' => true]],
            'tone' => 'positive', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
        $driver = new class($candidate) implements Driver
        {
            public int $calls = 0;

            public function __construct(private array $candidate) {}

            public function complete(array $body): array
            {
                $this->calls++;

                return ['content' => json_encode($this->candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'toolCalls' => [],
                    'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
            }
        };
        app()->instance(FakeDriver::class, $driver);
        $actual = new EvidenceEvaluator(app(AiGateway::class), $gate, app(EvidenceValidator::class), app(ConfirmationValidator::class), app(SourceExtractor::class));
        $result = app(TenantContextRunner::class)->run($tenant, fn () => $actual->evaluate($tenant, $answer));
        self::assertSame('confirmed', $result['status']);
        self::assertSame(1, $driver->calls);
        self::assertSame(1, DB::table('ai_calls')->count());
        $unsafe = ['name' => 'СтальПлюс', 'aliases' => ['СтальПлюс'], 'facts' => [['topic' => 'contact', 'value' => 'user at example.test']]];
        DB::table('measurements')->where('id', $measurement)->update([
            'profile_snapshot' => json_encode($unsafe, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($unsafe),
        ]);
        $blocked = app(TenantContextRunner::class)->run($tenant, fn () => $actual->evaluate($tenant, $answer));
        self::assertSame('unknown', $blocked['status']);
        self::assertSame(1, $driver->calls);
        self::assertSame(1, DB::table('ai_calls')->count());
        $other = new TenantContext((string) Str::ulid(), (string) Str::ulid(), source: 'job');
        self::assertSame('unknown', app(TenantContextRunner::class)->run($tenant, fn () => $actual->evaluate($other, $answer))['status']);
        $profile = ['name' => 'СтальПлюс', 'aliases' => ['СтальПлюс'], 'facts' => []];
        DB::table('measurements')->where('id', $measurement)->update([
            'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile),
        ]);
        DB::table('measurement_answers')->where('id', $answer)->update(['answer_text' => 'МеталлСевер поставляет металл.']);
        $gateway->responses = [];
        $gateway->result = ['brand_mentioned' => false, 'brand_position' => null, 'brand_recommended' => false,
            'mention_quote' => null, 'companies' => [['name' => 'МеталлСевер', 'position' => 1, 'role' => 'supplier', 'is_brand' => false]],
            'tone' => 'none', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
        $absent = $evaluate();
        self::assertSame('confirmed', $absent['status']);
        self::assertFalse($absent['fact']['brand_mentioned']);

        $profile = ['name' => 'Торты', 'aliases' => ['Торты'], 'category' => 'торты', 'facts' => []];
        DB::table('measurements')->where('id', $measurement)->update([
            'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile),
        ]);
        DB::table('measurement_answers')->where('id', $answer)->update(['answer_text' => 'Торты продаются здесь.']);
        $gateway->result['companies'] = [['name' => 'Торты', 'position' => 1, 'role' => 'other', 'is_brand' => false]];
        self::assertSame('unknown', $evaluate()['status']);
        Http::assertNothingSent();
    }
}
