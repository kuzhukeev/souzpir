<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // A linked vendor directory can make Composer resolve the integration
        // checkout instead of the current worktree. Fail before migrations run.
        $expected = realpath(dirname(__DIR__));
        $cwd = realpath(getcwd());
        $inferred = realpath(Application::inferBasePath());
        if ($expected === false || $cwd === false || $inferred === false
            || strcasecmp($expected, $cwd) !== 0 || strcasecmp($expected, $inferred) !== 0) {
            throw new \RuntimeException('Test application root does not match the current worktree.');
        }

        return parent::createApplication();
    }
}
