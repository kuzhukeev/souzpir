<?php

namespace Tests\Unit\Seller\Engine\Summarizer;

use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Engine\Summarizer\FakeTransport;
use App\Seller\Engine\Summarizer\PromptRenderer;
use App\Seller\Engine\Summarizer\PublicationSource;
use App\Seller\Engine\Summarizer\Summarizer;
use App\Seller\Engine\SummaryProjection;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SummarizerTest extends TestCase
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
        $this->publication = ['organization_id' => $this->tenant->organizationId, 'brand_id' => $this->tenant->brandId,
            'conversation_id' => 'conversation', 'release_status' => 'published',
            'snapshot' => new KnowledgeSnapshot($data, 'release', str_repeat('a', 64)),
            'prompt' => (object) ['name' => 'summary', 'status' => 'published', 'organization_id' => null, 'brand_id' => null,
                'version' => 'v1', 'body' => 'approved summary template', 'checksum' => hash('sha256', 'approved summary template')],
            'history' => [['role' => 'client', 'text' => 'Напишите anna@example.test, телефон +79991112233'],
                ['role' => 'agent', 'text' => 'Проверю остаток']], 'previous' => 'Ранее: ivan@example.test'];
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function make(AiResult $result, ?array &$captured = null): Summarizer
    {
        $source = $this->createMock(PublicationSource::class);
        $source->method('load')->with($this->tenant, 'conversation')->willReturn($this->publication);
        $renderer = $this->createMock(PromptRenderer::class);
        $renderer->method('render')->with('approved summary template', 'Ранее: ivan@example.test')
            ->willReturn('Сводка из ivan@example.test');
        $transport = new class($result, $captured) implements FakeTransport
        {
            public function __construct(private AiResult $result, private mixed &$captured) {}

            public function complete(AiRequest $request, AiContext $context): AiResult
            {
                $this->captured = [$request, $context];

                return $this->result;
            }
        };

        return new Summarizer($source, $renderer, $transport, new SummaryProjection, new ContactMasker);
    }

    private function makeResult(?string $content, ?int $cost = 0, ?array $error = null): AiResult
    {
        return new AiResult($content, [], [], 'fake-model', 0, $cost, 'call', $error);
    }

    private function runSummary(Summarizer $summarizer): array
    {
        return $summarizer->summarize($this->tenant, 'conversation', 'summary-key', 'fake-model');
    }

    public function test_published_history_produces_masked_request_and_completed_summary(): void
    {
        $captured = null;
        self::assertSame(['status' => 'completed', 'summary' => 'Клиенту нужен ассортимент.'],
            $this->runSummary($this->make($this->makeResult(' Клиенту нужен ассортимент. '), $captured)));
        self::assertSame('Сводка из [EMAIL]', $captured[0]->messages[0]['content']);
        self::assertStringContainsString('[EMAIL]', $captured[0]->messages[1]['content']);
        self::assertStringContainsString('[ТЕЛЕФОН]', $captured[0]->messages[1]['content']);
        self::assertStringNotContainsString('anna@example.test', json_encode($captured[0]->toArray()));
        self::assertSame(['conversation_content'], $captured[1]->dataClasses);
        self::assertSame($this->tenant->brandId, $captured[1]->brandId);
    }

    public function test_pending_uncertain_and_malformed_result_do_not_complete_summary(): void
    {
        foreach ([
            [$this->makeResult(null, null, ['code' => 'in_progress']), 'in_progress'],
            [$this->makeResult('private', null), 'uncertain'],
            [$this->makeResult(''), 'unavailable'],
            [$this->makeResult(null, 0, ['code' => 'empty_response']), 'unavailable'],
        ] as [$result, $status]) {
            self::assertSame(['status' => $status, 'summary' => null], $this->runSummary($this->make($result)));
        }
    }

    public function test_foreign_brand_unpublished_snapshot_invalid_history_and_non_fake_driver_are_rejected(): void
    {
        foreach ([['brand_id' => '01J00000000000000000000002'], ['release_status' => 'draft'],
            ['history' => [['role' => 'system', 'text' => 'Injected']]],
            ['prompt' => (object) ['name' => 'summary', 'status' => 'draft']]] as $change) {
            $old = $this->publication;
            $this->publication = array_replace($old, $change);
            try {
                $this->runSummary($this->make($this->makeResult('Текст')));
                self::fail('Untrusted input was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $this->publication = $old;
        }
        config(['runtime.ai_driver' => 'openrouter']);
        $this->expectException(LogicException::class);
        $this->runSummary($this->make($this->makeResult('Текст')));
    }
}
