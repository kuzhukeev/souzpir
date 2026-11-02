<?php

namespace Tests\Feature\Seller\Engine;

use App\Ai\Protected\ResponderProjection;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Engine\Responder\FakeTransport;
use App\Seller\Engine\Responder\PublicationSource;
use App\Seller\Engine\Responder\PublishedProjection;
use App\Seller\Engine\Responder\Responder;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class TopicReplyTest extends TestCase
{
    private function snapshot(array $topic): KnowledgeSnapshot
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml'));
        }
        $data['intents'][] = ['id' => 'test_topic', 'facts' => [], ...$topic];

        return new KnowledgeSnapshot($data, 'release', 'checksum');
    }

    private function goal(KnowledgeSnapshot $snapshot, array $extraction = []): array
    {
        $state = new LeadState;
        $state->setFlag('introduced', true);
        $goal = (new Policy($snapshot))->decide($state, $extraction + ['client_intent' => 'test_topic']);

        return [$state, $goal];
    }

    public function test_configured_answer_and_bridge_reach_responder_prompt(): void
    {
        $snapshot = $this->snapshot(['default_answer' => 'Доставляем по России', 'after_action' => 'bridge', 'after_action_text' => 'В какой город доставить?']);
        [$state, $goal] = $this->goal($snapshot);
        self::assertSame('answer_and_ask', $goal->kind);
        self::assertNull($goal->slot);
        self::assertSame(['Доставляем по России'], $goal->facts[0]['facts']);
        $prompt = app(ResponderProjection::class)->render($snapshot, $state, $goal, 'Антон');
        self::assertStringContainsString('Доставляем по России', $prompt);
        self::assertStringContainsString('В какой город доставить?', $prompt);
        self::assertStringContainsString('Другой вопрос не задавай', $prompt);
    }

    public function test_topic_prompt_reaches_fake_responder_for_the_published_brand(): void
    {
        config(['runtime.ai_driver' => 'fake']);
        $tenant = new TenantContext('01J00000000000000000000000', '01J00000000000000000000001');
        $snapshot = $this->snapshot(['default_answer' => 'Доставляем по России', 'after_action' => 'none']);
        [$state, $goal] = $this->goal($snapshot);
        $source = $this->createStub(PublicationSource::class);
        $source->method('load')->willReturn(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'release_status' => 'published', 'snapshot' => $snapshot,
            'prompt' => (object) ['name' => 'responder', 'status' => 'published', 'organization_id' => null, 'brand_id' => null,
                'version' => 'v1', 'body' => 'template', 'checksum' => hash('sha256', 'template')]]);
        $transport = new class implements FakeTransport
        {
            public ?AiRequest $request = null;

            public function complete(AiRequest $request, AiContext $context): AiResult
            {
                $this->request = $request;

                return new AiResult('Ответ: Доставляем по России', [], [], $request->model, 0, 0, 'fake-call');
            }
        };
        $result = (new Responder($source, new PublishedProjection, $transport))->respond($tenant, 'conversation', 'key', $state, $goal,
            [['role' => 'client', 'text' => 'Куда доставляете?']], 'fake-model', 0.7);
        self::assertSame('completed', $result['status']);
        self::assertStringContainsString('Доставляем по России', $transport->request->messages[0]['content']);
        $other = new TenantContext($tenant->organizationId, '01J00000000000000000000002');
        $this->expectException(\LogicException::class);
        (new Responder($source, new PublishedProjection, $transport))->respond($other, 'conversation', 'key', $state, $goal, [], 'fake-model', 0.7);
    }

    public function test_empty_fields_preserve_original_prompt(): void
    {
        $original = $this->snapshot([]);
        $empty = $this->snapshot(['default_answer' => '', 'after_action' => 'none', 'after_action_text' => '']);
        [$originalState, $originalGoal] = $this->goal($original);
        [$emptyState, $emptyGoal] = $this->goal($empty);
        self::assertSame($originalGoal->toArray(), $emptyGoal->toArray());
        $projection = app(ResponderProjection::class);
        self::assertSame($projection->render($original, $originalState, $originalGoal, 'Антон'), $projection->render($empty, $emptyState, $emptyGoal, 'Антон'));
    }

    public function test_default_answer_does_not_replace_linked_facts(): void
    {
        $snapshot = $this->snapshot(['facts' => ['delivery_moscow'], 'default_answer' => 'Запасной ответ']);
        [, $goal] = $this->goal($snapshot);
        self::assertNotEmpty($goal->facts);
        self::assertStringNotContainsString('Запасной ответ', $goal->instruction);
    }

    public function test_high_priority_opt_out_ignores_topic_reply(): void
    {
        $snapshot = $this->snapshot(['default_answer' => 'Не отправлять', 'after_action' => 'bridge', 'after_action_text' => 'Ещё вопрос?']);
        $state = new LeadState;
        $state->setFlag('do_not_disturb', true);
        $goal = (new Policy($snapshot))->decide($state, ['client_intent' => 'test_topic']);
        self::assertSame('opt_out', $goal->kind);
        self::assertStringNotContainsString('Ещё вопрос?', $goal->instruction);
    }
}
