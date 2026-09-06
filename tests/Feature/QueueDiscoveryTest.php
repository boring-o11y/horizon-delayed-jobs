<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Feature;

use BoringO11y\HorizonDelayedJobs\Queues;
use BoringO11y\HorizonDelayedJobs\Tests\Fixtures\ExampleJob;
use BoringO11y\HorizonDelayedJobs\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class QueueDiscoveryTest extends TestCase
{
    public function test_it_discovers_the_queues_of_the_configured_supervisors()
    {
        $this->assertSame(
            [['redis', 'default'], ['redis', 'emails']],
            app(Queues::class)->all()->all()
        );
    }

    public function test_it_reads_a_comma_separated_supervisor_queue()
    {
        config(['horizon.environments.testing.supervisor-1.queue' => 'high,default']);

        $this->assertSame(
            [['redis', 'high'], ['redis', 'default']],
            app(Queues::class)->all()->all()
        );
    }

    public function test_it_merges_supervisor_defaults()
    {
        config([
            'horizon.defaults.supervisor-1' => ['connection' => 'redis', 'queue' => ['from-defaults']],
            'horizon.environments.testing.supervisor-1' => ['maxProcesses' => 3],
        ]);

        $this->assertSame(
            [['redis', 'from-defaults']],
            app(Queues::class)->all()->all()
        );
    }

    public function test_an_explicit_map_wins()
    {
        config(['horizon-delayed-jobs.queues' => ['redis' => ['only-this']]]);

        $this->assertSame(
            [['redis', 'only-this']],
            app(Queues::class)->all()->all()
        );
    }

    public function test_it_falls_back_to_the_default_queue_connection()
    {
        config(['horizon.environments' => [], 'horizon.defaults' => []]);

        $this->assertSame(
            [['redis', 'default']],
            app(Queues::class)->all()->all()
        );
    }

    public function test_a_queue_no_supervisor_covers_is_not_listed()
    {
        Queue::connection('redis')->later(60, new ExampleJob(1), null, 'unwatched');

        // A dashboard shows the queues Horizon is configured for. Naming the
        // queue explicitly is how you widen that.
        $this->assertSame(0, $this->getJson('horizon/delayed-jobs?type=')->json('total'));

        config(['horizon-delayed-jobs.queues' => ['redis' => ['unwatched']]]);

        $this->assertSame(1, $this->getJson('horizon/delayed-jobs?type=')->json('total'));
    }

    public function test_a_non_redis_connection_is_skipped()
    {
        config([
            'queue.connections.sync' => ['driver' => 'sync'],
            'horizon-delayed-jobs.queues' => ['sync' => ['default'], 'redis' => ['default']],
        ]);

        Queue::connection('redis')->later(60, new ExampleJob(1), null, 'default');

        $this->assertSame(1, $this->getJson('horizon/delayed-jobs?type=')->json('total'));
    }
}
