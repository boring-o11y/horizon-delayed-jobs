<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Feature;

use BoringO11y\HorizonDelayedJobs\QueueKeys;
use BoringO11y\HorizonDelayedJobs\Tests\Fixtures\ExampleJob;
use BoringO11y\HorizonDelayedJobs\Tests\Fixtures\OtherJob;
use BoringO11y\HorizonDelayedJobs\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Contracts\JobRepository;

class PerformNowTest extends TestCase
{
    public function test_it_moves_a_delayed_job_onto_the_ready_queue()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->getJson('horizon/delayed-jobs?type=')->json('jobs.0.id');

        $this->postJson("horizon/delayed-jobs/perform/{$id}")
            ->assertOk()
            ->assertJson(['performed' => true]);

        [$redis, $key] = $this->keys('default');

        $this->assertSame(0, $redis->zcard($key.':delayed'));
        $this->assertSame(1, $redis->llen($key));
        $this->assertSame(1, $redis->llen($key.':notify'));

        // The payload is untouched, so the job runs on the attempt it was on.
        $payload = json_decode($redis->lindex($key, 0), true);
        $this->assertSame($id, $payload['uuid']);
    }

    public function test_it_tells_horizon_the_job_is_pending_again()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->getJson('horizon/delayed-jobs?type=')->json('jobs.0.id');

        $this->postJson("horizon/delayed-jobs/perform/{$id}")->assertOk();

        $job = app(JobRepository::class)->getJobs([$id])->first();

        $this->assertSame('pending', $job->status);
    }

    public function test_the_promoted_job_can_then_be_popped()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->getJson('horizon/delayed-jobs?type=')->json('jobs.0.id');

        $this->postJson("horizon/delayed-jobs/perform/{$id}")->assertOk();

        $popped = Queue::connection('redis')->pop('default');

        $this->assertNotNull($popped);
        $this->assertSame($id, $popped->uuid());
    }

    public function test_it_finds_a_job_on_a_queue_the_hint_did_not_name()
    {
        Queue::connection('redis')->later(3600, new OtherJob, null, 'emails');

        $id = $this->getJson('horizon/delayed-jobs?type=')->json('jobs.0.id');

        // A stale hint must fall back to a search rather than report the job gone.
        $this->postJson("horizon/delayed-jobs/perform/{$id}", [
            'connection' => 'redis',
            'queue' => 'default',
        ])->assertOk()->assertJson(['performed' => true]);

        [$redis, $key] = $this->keys('emails');

        $this->assertSame(1, $redis->llen($key));
    }

    public function test_an_unknown_job_is_a_404()
    {
        $this->postJson('horizon/delayed-jobs/perform/does-not-exist')
            ->assertNotFound()
            ->assertJson(['performed' => false]);
    }

    public function test_it_promotes_many_jobs_at_once()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');
        Queue::connection('redis')->later(3600, new ExampleJob(2), null, 'default');
        Queue::connection('redis')->later(3600, new OtherJob, null, 'emails');

        $ids = $this->getJson('horizon/delayed-jobs?type=')->json('jobs.*.id');

        $response = $this->postJson('horizon/delayed-jobs/perform', [
            'ids' => array_merge($ids, ['does-not-exist']),
        ])->assertOk();

        $this->assertSame(3, $response->json('count'));
        $this->assertSame(0, $this->getJson('horizon/delayed-jobs?type=')->json('total'));
    }

    public function test_promoting_the_same_job_twice_only_queues_it_once()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->getJson('horizon/delayed-jobs?type=')->json('jobs.0.id');

        $this->postJson("horizon/delayed-jobs/perform/{$id}")->assertOk();
        $this->postJson("horizon/delayed-jobs/perform/{$id}")->assertNotFound();

        [$redis, $key] = $this->keys('default');

        $this->assertSame(1, $redis->llen($key));
    }

    public function test_the_routes_are_gone_when_performing_is_turned_off()
    {
        $this->overrides = ['horizon-delayed-jobs.perform_now' => false];

        // The routes are declared once, at boot, so the application has to be
        // rebuilt for the configuration change to take effect.
        $this->refreshApplication();

        // Horizon's catch-all answers every path under its prefix, so "gone"
        // is a route that was never declared rather than a 404.
        $this->assertFalse(Route::has('horizon-delayed-jobs.perform'));
        $this->assertFalse(Route::has('horizon-delayed-jobs.perform-many'));
        $this->assertTrue(Route::has('horizon-delayed-jobs.index'));

        $this->getJson('horizon/delayed-jobs?type=')->assertOk();
    }

    /**
     * @return array{0: \Illuminate\Redis\Connections\Connection, 1: string}
     */
    protected function keys(string $queue)
    {
        return app(QueueKeys::class)->resolve('redis', $queue);
    }
}
