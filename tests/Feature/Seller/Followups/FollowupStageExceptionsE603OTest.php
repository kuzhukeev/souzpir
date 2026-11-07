<?php

namespace Tests\Feature\Seller\Followups;

use App\Seller\Followups\FollowupPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class FollowupStageExceptionsE603OTest extends TestCase
{
    public function test_configured_stage_is_excluded_without_changing_mandatory_rules(): void
    {
        $now = CarbonImmutable::parse('2026-11-05 12:00:00 UTC');
        $lastAgent = $now->subHours(24);
        $conversation = ['stage' => 'need', 'last_client_message_at' => $now->subDays(2)->toIso8601String(), 'state' => []];
        $policy = new FollowupPolicy;
        self::assertSame('silence', $policy->due($conversation, ['first_hours' => 20], $lastAgent, $now, 'UTC')['reason']);
        self::assertNull($policy->due($conversation, ['first_hours' => 20, 'excluded_stages' => ['need']], $lastAgent, $now, 'UTC'));
        self::assertNotNull($policy->due($conversation, ['first_hours' => 20, 'excluded_stages' => ['budget']], $lastAgent, $now, 'UTC'));
        self::assertNull($policy->due([...$conversation, 'stage' => 'handoff'], ['excluded_stages' => []], $lastAgent, $now, 'UTC'));
        self::assertNull($policy->due([...$conversation, 'do_not_disturb' => true], ['excluded_stages' => []], $lastAgent, $now, 'UTC'));
    }
}
