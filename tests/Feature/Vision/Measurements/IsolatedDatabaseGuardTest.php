<?php

namespace Tests\Feature\Vision\Measurements;

use LogicException;
use Tests\Feature\Vision\Measurements\Fixtures\IsolatedDatabaseGuard;
use Tests\TestCase;

final class IsolatedDatabaseGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_E804_ACCESS_RACE') !== '1') {
            $this->markTestSkipped('Requires explicitly configured isolated E8-04 test database.');
        }
        IsolatedDatabaseGuard::check();
    }

    public function test_guard_rejects_missing_or_non_test_database_identity(): void
    {
        $original = (string) getenv('E804_RACE_TEST_DB');
        try {
            foreach (['', 'souzpir_dev', 'souzpir_test_other'] as $value) {
                putenv('E804_RACE_TEST_DB='.$value);
                try {
                    IsolatedDatabaseGuard::check();
                    $this->fail('A missing, non-test or mismatched database was accepted.');
                } catch (LogicException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            putenv('E804_RACE_TEST_DB='.$original);
        }
    }

    public function test_guard_rejects_preview_database_and_mismatched_role(): void
    {
        $role = (string) getenv('E804_RACE_TEST_ROLE');
        $preview = config('runtime.preview_database');
        try {
            config(['runtime.preview_database' => (string) getenv('E804_RACE_TEST_DB')]);
            try {
                IsolatedDatabaseGuard::check();
                $this->fail('The configured preview database was accepted.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
            config(['runtime.preview_database' => $preview]);
            putenv('E804_RACE_TEST_ROLE=e804_wrong_role');
            try {
                IsolatedDatabaseGuard::check();
                $this->fail('A mismatched role was accepted.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        } finally {
            config(['runtime.preview_database' => $preview]);
            putenv('E804_RACE_TEST_ROLE='.$role);
        }
    }

    public function test_guard_rejects_non_loopback_or_mismatched_endpoint(): void
    {
        $host = (string) getenv('E804_RACE_TEST_HOST');
        $port = (string) getenv('E804_RACE_TEST_PORT');
        try {
            foreach ([['192.0.2.1', $port], ['127.0.0.2', $port], [$host, '0']] as [$candidateHost, $candidatePort]) {
                putenv('E804_RACE_TEST_HOST='.$candidateHost);
                putenv('E804_RACE_TEST_PORT='.$candidatePort);
                try {
                    IsolatedDatabaseGuard::check();
                    $this->fail('A non-loopback or mismatched endpoint was accepted.');
                } catch (LogicException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            putenv('E804_RACE_TEST_HOST='.$host);
            putenv('E804_RACE_TEST_PORT='.$port);
        }
    }
}
