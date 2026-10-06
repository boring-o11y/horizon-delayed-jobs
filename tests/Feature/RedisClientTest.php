<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Feature;

use BoringO11y\HorizonDelayedJobs\Tests\TestCase;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Redis;

class RedisClientTest extends TestCase
{
    public function test_it_reports_the_client_under_test()
    {
        // Both clients shape Lua replies differently, so the suite is run
        // against each in CI. This makes it obvious which one a given run
        // actually exercised.
        $this->assertContains(env('REDIS_CLIENT', 'phpredis'), ['phpredis', 'predis']);

        $expected = env('REDIS_CLIENT', 'phpredis') === 'predis'
            ? PredisConnection::class
            : PhpRedisConnection::class;

        $this->assertInstanceOf($expected, Redis::connection());
    }
}
