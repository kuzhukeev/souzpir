<?php

namespace Tests\Unit\Seller\Followups;

use App\Seller\Followups\FollowupPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class FollowupPolicyTest extends TestCase
{
    private function conversation(int $count = 0): array
    {
        return ['stage' => 'need', 'do_not_disturb' => false, 'last_client_message_at' => '2026-11-01 00:00:00+00', 'state' => ['counters' => ['followups_sent' => $count]]];
    }

    public function test_null_second_interval_uses_twenty_times_sequence(): void
    {
        $policy = new FollowupPolicy;
        $last = CarbonImmutable::parse('2026-11-01 01:00:00+00');
        self::assertNull($policy->due($this->conversation(), [], $last, $last->addHours(19), 'UTC'));
        self::assertSame(1, $policy->due($this->conversation(), [], $last, $last->addHours(20), 'UTC')['sequence']);
        self::assertNull($policy->due($this->conversation(1), ['second_hours' => null], $last, $last->addHours(39), 'UTC'));
        self::assertSame(2, $policy->due($this->conversation(1), ['second_hours' => null], $last, $last->addHours(40), 'UTC')['sequence']);
    }

    public function test_configured_second_interval_window_and_exclusions(): void
    {
        $policy = new FollowupPolicy;
        $last = CarbonImmutable::parse('2026-11-01 01:00:00+00');
        $settings = ['first_hours' => 10, 'second_hours' => 24, 'window' => ['start' => '09:00', 'end' => '20:00']];
        self::assertNull($policy->due($this->conversation(1), $settings, $last, $last->addHours(24), 'UTC'));
        self::assertNotNull($policy->due($this->conversation(1), $settings, $last, $last->addHours(32), 'UTC'));
        foreach (['handoff', 'lost', 'done', 'spam'] as $stage) {
            $row = $this->conversation();
            $row['stage'] = $stage;
            self::assertNull($policy->due($row, [], $last, $last->addHours(40), 'UTC'));
        }
        $row = $this->conversation();
        $row['do_not_disturb'] = true;
        self::assertNull($policy->due($row, [], $last, $last->addHours(40), 'UTC'));
    }

    public function test_agreed_date_and_invalid_date(): void
    {
        $policy = new FollowupPolicy;
        $row = $this->conversation();
        $row['state']['slots']['next_contact_at'] = '2026-11-02 10:00:00+00';
        $now = CarbonImmutable::parse('2026-11-02 11:00:00+00');
        self::assertSame('next_contact_at', $policy->due($row, [], null, $now, 'UTC')['reason']);
        $row['state']['slots']['next_contact_at'] = 'не дата';
        self::assertNull($policy->due($row, [], null, $now, 'UTC'));
    }
}
