<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->make('config')->set('identity.lookup_key', str_repeat('test-only-identity-key-', 3));
        if ($app->make('config')->get('database.default') === 'mysql'
            && ! str_starts_with((string) $app->make('config')->get('database.connections.mysql.database'), 'moshkelti_test_')) {
            throw new \LogicException('MySQL tests may only run against a dedicated moshkelti_test_* database.');
        }

        return $app;
    }
}
