<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Feature;

use BoringO11y\HorizonDelayedJobs\Tests\Fixtures\ExampleJob;
use BoringO11y\HorizonDelayedJobs\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * The queue keys are addressed through Lua, which is how the framework's own
 * migrate() and size() address them. These cover the case that would break a
 * package that mixed the two styles: an application with a Redis key prefix,
 * where a listing and a button that disagreed about the key would show jobs
 * whose "run now" silently did nothing.
 */
class RedisPrefixTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.options.prefix', 'prefixed_database_');
    }

    public function test_it_lists_delayed_jobs_when_a_key_prefix_is_configured()
    {
        Queue::connection('redis')->later(60, new ExampleJob(1), null, 'default');

        $response = $this->getJson('horizon/delayed-jobs?type=')->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame(ExampleJob::class, $response->json('jobs.0.name'));
    }

    public function test_it_promotes_a_delayed_job_when_a_key_prefix_is_configured()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->getJson('horizon/delayed-jobs?type=')->json('jobs.0.id');

        $this->postJson("horizon/delayed-jobs/perform/{$id}")
            ->assertOk()
            ->assertJson(['performed' => true]);

        $this->assertSame(0, $this->getJson('horizon/delayed-jobs?type=')->json('total'));

        $popped = Queue::connection('redis')->pop('default');

        $this->assertNotNull($popped);
        $this->assertSame($id, $popped->uuid());
    }

    public function test_the_keys_really_are_prefixed()
    {
        Queue::connection('redis')->later(60, new ExampleJob(1), null, 'default');

        // Guards the two tests above: without this, a prefix that silently did
        // not apply would let them pass while proving nothing.
        $keys = Redis::connection()->client()->keys('*');

        $this->assertNotEmpty(array_filter(
            $keys, fn ($key) => str_contains($key, 'prefixed_database_queues:default:delayed')
        ));
    }
}
