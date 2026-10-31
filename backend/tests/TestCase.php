<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        // Docker process environment can take precedence over PHPUnit's env values.
        // Pin the connection before RefreshDatabase can run any migrations.
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.database', 'ged_test');
        $app['config']->set('database.connections.pgsql.url', null);

        return $app;
    }
}
