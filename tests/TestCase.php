<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\IsolatedTestEnvironmentGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        IsolatedTestEnvironmentGuard::assertBeforeBootstrap(dirname(__DIR__));
        $application = parent::createApplication();
        IsolatedTestEnvironmentGuard::assertSafe($application);

        return $application;
    }
}
