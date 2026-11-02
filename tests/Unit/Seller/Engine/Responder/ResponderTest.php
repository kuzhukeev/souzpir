<?php

namespace Tests\Unit\Seller\Engine\Responder;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Responder\FakeTransport;
use App\Seller\Engine\Responder\Projection;
use App\Seller\Engine\Responder\PublicationSource;
use App\Seller\Engine\Responder\Responder;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ResponderTest extends TestCase
{
    private TenantContext $tenant;

    private array $publication;

    private mixed $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['runtime' => ['ai_driver' => 'fake']]));
        Container::setInstance($container);
        $this->tenant = new TenantContext('01J00000000000000000000000', '01J00000000000000000000001');
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['persona'] = ['name' => 'Антон'];
        $this->publication = ['organization_id' => $this->tenant->organizationId, 'brand_id' => $this->tenant->brandId,
            'release_status' => 'published', 'snapshot' => new KnowledgeSnapshot($data, 'release', str_repeat('a', 64)),
            'prompt' => (object) ['name' => 'responder', 'status' => 'published', 'organization_id' => null, 'brand_id' => null,
                'version' => 'v1', 'body' => 'approved template', 'checksum' => hash('sha256', 'approved template')]];
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function responder(AiResult $result, ?array &$captured = null): Responder
    {
        $source = $this->createMock(PublicationSource::class);
        $source->method('load')->willReturn($this->publication);
        $projection = $this->createMock(Projection::class);
        $projection->method('render')->with($this->publication['snapshot'], self::isInstanceOf(LeadState::class), self::isInstanceOf(Goal::class), 'Антон')->willReturn('approved rendered prompt');
        $transport = new class($result, $captured) implements FakeTransport
        {
            public function __construct(private AiResult $result, private mixed &$captured) {}

            public function complete(AiRequest $request, AiContext $context): AiResult
            {
                $this->captured = [$request, $context];

                return $this->result;
            }
        };

        return new Responder($source, $projection, $transport);
    }

    private function runResponder(Responder $responder): array
    {
        return $responder->respond($this->tenant, 'conversation', 'key', new LeadState, new Goal('answer_only'),
            [['role' => 'client', 'text' => 'Здравствуйте']], 'fake-model', 0.7);
    }

    private function makeResult(?string $text, ?int $cost = 0, ?array $error = null): AiResult
    {
        return new AiResult($text, [], [], 'fake-model', 0, $cost, 'call', $error);
    }

    public function test_builds_request_from_published_input_and_classifies_data(): void
    {
        $captured = null;
        $result = $this->runResponder($this->responder($this->makeResult('Ответ: Добрый день'), $captured));
        self::assertSame(['status' => 'completed', 'text' => 'Добрый день'], $result);
        self::assertSame('approved rendered prompt', $captured[0]->messages[0]['content']);
        self::assertSame('Здравствуйте', $captured[0]->messages[1]['content']);
        self::assertSame(['conversation_content', 'client_profile', 'knowledge_internal'], $captured[1]->dataClasses);
        self::assertSame($this->tenant->brandId, $captured[1]->brandId);
    }

    public function test_pending_uncertain_and_invalid_result_remain_distinct(): void
    {
        self::assertSame('in_progress', $this->runResponder($this->responder($this->makeResult(null, null, ['code' => 'in_progress'])))['status']);
        self::assertSame('uncertain', $this->runResponder($this->responder($this->makeResult('text', null)))['status']);
        self::assertSame('unavailable', $this->runResponder($this->responder($this->makeResult('')))['status']);
    }

    public function test_foreign_brand_and_unpublished_release_are_rejected(): void
    {
        foreach ([['brand_id' => '01J00000000000000000000002'], ['release_status' => 'draft']] as $change) {
            $original = $this->publication;
            $this->publication = array_replace($original, $change);
            try {
                $this->runResponder($this->responder($this->makeResult('text')));
                self::fail('Untrusted publication was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $this->publication = $original;
        }
    }

    public function test_foreign_or_unpublished_prompt_and_non_fake_driver_are_rejected(): void
    {
        foreach ([['organization_id' => $this->tenant->organizationId, 'brand_id' => '01J00000000000000000000002'], ['status' => 'draft'], ['checksum' => str_repeat('b', 64)]] as $change) {
            $original = $this->publication['prompt'];
            $this->publication['prompt'] = (object) array_replace((array) $original, $change);
            try {
                $this->runResponder($this->responder($this->makeResult('text')));
                self::fail('Untrusted prompt was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $this->publication['prompt'] = $original;
        }
        config(['runtime.ai_driver' => 'openrouter']);
        $this->expectException(LogicException::class);
        $this->runResponder($this->responder($this->makeResult('text')));
    }
}
