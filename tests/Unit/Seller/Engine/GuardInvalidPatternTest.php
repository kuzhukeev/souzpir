<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use LogicException;
use PHPUnit\Framework\TestCase;

final class GuardInvalidPatternTest extends TestCase
{
    public function test_invalid_patterns_fail_closed_without_disclosing_the_reply_or_pattern(): void
    {
        $reply = 'PRIVATE_REPLY_MARKER is a synthetic response';
        $invalid = '[PRIVATE_PATTERN_MARKER';

        foreach (['forbidden_promises', 'style', 'closed_topics', 'off_topic'] as $section) {
            foreach ([[$invalid], ['PRIVATE_REPLY_MARKER', $invalid]] as $patterns) {
                $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
                $data['guard_rules'][$section] = [[
                    'id' => 'synthetic',
                    'severity' => 'block',
                    'description' => 'synthetic rule',
                    'only_when_goal' => ['closed_topic'],
                    'patterns' => $patterns,
                ]];
                $guard = new DeterministicGuard(new KnowledgeSnapshot($data, 'fixture', 'fixture'));
                $warnings = [];
                set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                    $warnings[] = $message;

                    return true;
                });
                try {
                    try {
                        $guard->check($reply, new Goal('closed_topic'), new LeadState);
                        self::fail('Invalid regular expression was accepted in '.$section);
                    } catch (LogicException $error) {
                        self::assertSame('Seller guard could not safely evaluate the reply.', $error->getMessage());
                        self::assertNull($error->getPrevious());
                        self::assertStringNotContainsString($reply, (string) $error);
                        self::assertStringNotContainsString($invalid, (string) $error);
                    }
                    self::assertSame([], $warnings, 'PCRE warning escaped to the caller.');
                    trigger_error('synthetic handler probe', E_USER_WARNING);
                    self::assertSame(['synthetic handler probe'], $warnings, 'The caller error handler was not restored.');
                } finally {
                    restore_error_handler();
                }
            }
        }
    }
}
