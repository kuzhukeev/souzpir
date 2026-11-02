<?php

namespace Tests\Unit\Seller\Engine\Guard;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\Goal;
use App\Seller\Engine\Guard\FakeModel;
use App\Seller\Engine\Guard\LlmCheck;
use App\Seller\Engine\Guard\VerifiedSource;
use App\Seller\Engine\GuardRequest;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class LlmCheckTest extends TestCase
{
    private mixed $previousContainer;

    private TurnClaim $claim;

    private array $sourceData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['runtime' => ['ai_driver' => 'fake']]));
        Container::setInstance($container);
        $this->claim = new TurnClaim('claimed', 'turn', 'conversation', 'organization', 'brand', 0);
        $request = (new GuardRequest)->build('approved guard prompt', 'reply', 'fake-model', 'v1', str_repeat('a', 64));
        $context = new AiContext('organization', 'brand', 'guard', 'seller', 'conversation', 'conversation', 'guard-key',
            ['conversation_content', 'client_profile', 'knowledge_internal']);
        $this->sourceData = [$request, $context, ['verdict' => 'ok', 'issues' => [], 'warnings' => [], 'words' => 1, 'questions' => 0], new Goal('answer_and_ask')];
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function evaluate(AiResult $result, ?int &$calls = null): array
    {
        $source = $this->createMock(VerifiedSource::class);
        $source->expects(self::once())->method('load')->willReturn($this->sourceData);
        $model = new class($result, $calls) implements FakeModel
        {
            public function __construct(private AiResult $result, private mixed &$calls) {}

            public function complete(AiRequest $request, AiContext $context): AiResult
            {
                $this->calls = ($this->calls ?? 0) + 1;

                return $this->result;
            }
        };

        return (new LlmCheck($source, $model))->check(Request::create('/'), $this->claim, 'stage');
    }

    private function makeResult(?string $content, ?int $cost = 0, ?array $error = null): AiResult
    {
        return new AiResult($content, [], [], 'fake-model', 0, $cost, 'call', $error);
    }

    public function test_model_approval_and_rejection_follow_deterministic_rules(): void
    {
        $calls = 0;
        $result = $this->evaluate($this->makeResult('{"ok":true,"issues":[]}'), $calls);
        self::assertSame('completed', $result['status']);
        self::assertSame('ok', $result['verdict']);
        self::assertSame(1, $calls);
        $result = $this->evaluate($this->makeResult('{"ok":false,"issues":["Неподтверждённая цена"]}'));
        self::assertSame('regenerate', $result['verdict']);
        self::assertSame(['Неподтверждённая цена'], $result['issues']);
    }

    public function test_pending_uncertain_and_malformed_never_authorize(): void
    {
        foreach ([[$this->makeResult(null, null, ['code' => 'in_progress']), 'in_progress'],
            [$this->makeResult('text', null), 'uncertain'], [$this->makeResult('{bad'), 'uncertain'],
            [$this->makeResult('{"ok":false,"issues":[]}'), 'uncertain'],
            [$this->makeResult('text {"ok":true,"issues":[]}'), 'uncertain'],
            [$this->makeResult('{"ok":true,"issues":["ошибка"]}'), 'uncertain'],
            [$this->makeResult('{"ok":true,"ok":true,"issues":[]}'), 'uncertain']] as [$modelResult, $status]) {
            $result = $this->evaluate($modelResult);
            self::assertSame($status, $result['status']);
            self::assertNull($result['verdict']);
        }
    }

    public function test_rule_failure_and_foreign_brand_do_not_call_the_model(): void
    {
        $calls = 0;
        $this->sourceData[2] = ['verdict' => 'regenerate', 'issues' => ['Нарушено правило'], 'warnings' => [], 'words' => 1, 'questions' => 0];
        self::assertSame('regenerate', $this->evaluate($this->makeResult('{"ok":true,"issues":[]}'), $calls)['verdict']);
        self::assertSame(0, $calls);
        $this->sourceData[1] = new AiContext('organization', 'other-brand', 'guard', 'seller', 'conversation', 'conversation', 'key', ['conversation_content', 'client_profile', 'knowledge_internal']);
        self::assertSame('uncertain', $this->evaluate($this->makeResult('{"ok":true,"issues":[]}'), $calls)['status']);
        self::assertSame(0, $calls);
    }

    public function test_invalid_source_and_non_fake_runtime_fail_closed(): void
    {
        $source = $this->createMock(VerifiedSource::class);
        $source->expects(self::once())->method('load')->willThrowException(new \LogicException('unverified'));
        $model = $this->createMock(FakeModel::class);
        $model->expects(self::never())->method('complete');
        $guard = new LlmCheck($source, $model);
        self::assertSame('uncertain', $guard->check(Request::create('/'), $this->claim, 'stage')['status']);

        config(['runtime.ai_driver' => 'openrouter']);
        self::assertSame('uncertain', $guard->check(Request::create('/'), $this->claim, 'stage')['status']);
    }
}
