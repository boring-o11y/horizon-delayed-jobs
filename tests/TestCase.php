<?php

namespace BoringO11y\HorizonDelayedJobs\Tests;

use BoringO11y\HorizonDelayedJobs\HorizonDelayedJobsServiceProvider;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\HorizonServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Configuration applied on every application build.
     *
     * Some of what this package reads is only read once, at boot, so testing
     * it means rebuilding the application — and a value set on the old one
     * would not survive. Setting it here instead makes it part of every build.
     *
     * @var array<string, mixed>
     */
    protected array $overrides = [];

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection()->flushdb();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            HorizonServiceProvider::class,
            HorizonDelayedJobsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'phpredis'));
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 5,
        ]);

        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
        ]);

        $app['config']->set('horizon.path', 'horizon');
        $app['config']->set('horizon.middleware', []);
        $app['config']->set('horizon.environments.testing.supervisor-1', [
            'connection' => 'redis',
            'queue' => ['default', 'emails'],
        ]);

        // The dashboard's own gate is the local-environment default, which the
        // testing environment does not satisfy, so it is opened explicitly for
        // the tests that go through the routes.
        \Laravel\Horizon\Horizon::auth(fn () => true);

        foreach ($this->overrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
