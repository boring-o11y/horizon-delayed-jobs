<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Feature;

use BoringO11y\HorizonDelayedJobs\Tests\Fixtures\ExampleJob;
use BoringO11y\HorizonDelayedJobs\Tests\Fixtures\OtherJob;
use BoringO11y\HorizonDelayedJobs\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class DelayedJobsListingTest extends TestCase
{
    public function test_it_lists_delayed_jobs_across_every_configured_queue()
    {
        Queue::connection('redis')->later(60, new ExampleJob(1), null, 'default');
        Queue::connection('redis')->later(90, new ExampleJob(2), null, 'default');
        Queue::connection('redis')->later(30, new OtherJob, null, 'emails');

        $response = $this->getJson('horizon/delayed-jobs?type=')->assertOk();

        $this->assertSame(3, $response->json('total'));
        $this->assertSame(3, $response->json('matching'));
        $this->assertFalse($response->json('truncated'));
        $this->assertEqualsCanonicalizing(['default', 'emails'], $response->json('queues'));
    }

    public function test_jobs_are_ordered_by_when_they_become_available()
    {
        Queue::connection('redis')->later(300, new ExampleJob(1), null, 'default');
        Queue::connection('redis')->later(30, new OtherJob, null, 'emails');

        $names = $this->getJson('horizon/delayed-jobs?type=')->json('jobs.*.name');

        $this->assertSame([OtherJob::class, ExampleJob::class], $names);
    }

    public function test_a_released_job_is_a_retry_and_a_scheduled_one_is_not()
    {
        Queue::connection('redis')->push(new ExampleJob(1), null, 'default');
        Queue::connection('redis')->later(600, new OtherJob, null, 'default');

        // Popping and releasing is the real retry path: the pop script bumps
        // the payload's attempts, which is what tells the two apart.
        Queue::connection('redis')->pop('default')->release(120);

        $retries = $this->getJson('horizon/delayed-jobs?type=retries')->assertOk();
        $scheduled = $this->getJson('horizon/delayed-jobs?type=scheduled')->assertOk();

        $this->assertSame(1, $retries->json('matching'));
        $this->assertSame(ExampleJob::class, $retries->json('jobs.0.name'));
        $this->assertSame(1, $retries->json('jobs.0.attempts'));
        $this->assertTrue($retries->json('jobs.0.is_retry'));

        $this->assertSame(1, $scheduled->json('matching'));
        $this->assertSame(OtherJob::class, $scheduled->json('jobs.0.name'));

        // The unfiltered total still counts everything that is delayed.
        $this->assertSame(2, $retries->json('total'));
    }

    public function test_it_filters_by_queue_and_by_name()
    {
        Queue::connection('redis')->later(60, new ExampleJob(1), null, 'default');
        Queue::connection('redis')->later(60, new OtherJob, null, 'emails');

        $this->assertSame(1, $this->getJson('horizon/delayed-jobs?type=&queue=emails')->json('matching'));
        $this->assertSame(1, $this->getJson('horizon/delayed-jobs?type=&search=Example')->json('matching'));
        $this->assertSame(1, $this->getJson('horizon/delayed-jobs?type=&search=example')->json('matching'));
        $this->assertSame(0, $this->getJson('horizon/delayed-jobs?type=&search=Nothing')->json('matching'));
    }

    public function test_it_paginates()
    {
        foreach (range(1, 5) as $i) {
            Queue::connection('redis')->later($i * 60, new ExampleJob($i), null, 'default');
        }

        $first = $this->getJson('horizon/delayed-jobs?type=&per_page=2&page=1')->assertOk();
        $third = $this->getJson('horizon/delayed-jobs?type=&per_page=2&page=3')->assertOk();

        $this->assertCount(2, $first->json('jobs'));
        $this->assertCount(1, $third->json('jobs'));
        $this->assertSame(5, $first->json('matching'));
    }

    public function test_it_reports_a_truncated_listing()
    {
        config(['horizon-delayed-jobs.scan_limit' => 1]);

        Queue::connection('redis')->later(60, new ExampleJob(1), null, 'default');
        Queue::connection('redis')->later(90, new ExampleJob(2), null, 'default');

        $response = $this->getJson('horizon/delayed-jobs?type=')->assertOk();

        $this->assertTrue($response->json('truncated'));
        $this->assertSame(2, $response->json('total'));
        $this->assertCount(1, $response->json('jobs'));
    }

    public function test_the_listing_is_empty_when_nothing_is_delayed()
    {
        $response = $this->getJson('horizon/delayed-jobs?type=')->assertOk();

        $this->assertSame(0, $response->json('total'));
        $this->assertSame([], $response->json('jobs'));
    }
}
