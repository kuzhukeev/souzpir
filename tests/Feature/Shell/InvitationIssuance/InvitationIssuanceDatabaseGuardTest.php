<?php

namespace Tests\Feature\Shell\InvitationIssuance;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Shell\InvitationIssuance\Fixtures\IsolatedInvitationDatabase;
use Tests\TestCase;

final class InvitationIssuanceDatabaseGuardTest extends TestCase
{
    public function test_missing_or_mismatched_test_database_and_preview_are_rejected(): void
    {
        $target = IsolatedInvitationDatabase::assert();
        $oldTest = config('runtime.test_database');
        $oldPreview = config('runtime.preview_database');
        try {
            foreach ([null, '', 'production', $target['database'].'_other'] as $wrong) {
                config(['runtime.test_database' => $wrong]);
                try {
                    IsolatedInvitationDatabase::assert();
                    self::fail('An absent or different explicit test database must be rejected.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
            config(['runtime.test_database' => $oldTest, 'runtime.preview_database' => $target['database']]);
            try {
                IsolatedInvitationDatabase::assert();
                self::fail('The preview database must never be migrated by a test.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        } finally {
            config(['runtime.test_database' => $oldTest, 'runtime.preview_database' => $oldPreview]);
        }
    }

    public function test_child_target_must_match_all_parent_connection_identity_fields(): void
    {
        $target = IsolatedInvitationDatabase::assert();
        self::assertSame($target, IsolatedInvitationDatabase::assert($target));
        foreach (['database', 'role', 'server', 'port', 'client'] as $field) {
            $different = $target;
            $different[$field] = $field === 'port' ? $target[$field] + 1 : 'different';
            try {
                IsolatedInvitationDatabase::assert($different);
                self::fail('The child must use exactly the parent connection identity.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }

    #[Group('race')]
    public function test_worker_rejects_a_different_parent_database_before_fixture_access(): void
    {
        $target = IsolatedInvitationDatabase::assert();
        $target['database'] .= '_other';
        $worker = new Process([PHP_BINARY, base_path('tests/Feature/Shell/InvitationIssuance/Fixtures/issue-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'E106B_TARGET' => json_encode($target, JSON_THROW_ON_ERROR),
        ], timeout: 20);
        $worker->run();
        self::assertNotSame(0, $worker->getExitCode());
        self::assertStringContainsString('Test database guard rejected the child connection.', $worker->getOutput().$worker->getErrorOutput());
        self::assertStringNotContainsString('ready', $worker->getOutput());
    }
}
