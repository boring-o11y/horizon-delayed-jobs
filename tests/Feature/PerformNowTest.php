<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Feature;

use BoringO11y\HorizonDelayedJobs\QueueKeys;
use BoringO11y\HorizonDelayedJobs\Tests\Fixtures\ExampleJob;
use BoringO11y\HorizonDelayedJobs\Tests\Fixtures\OtherJob;
use BoringO11y\HorizonDelayedJobs\Tests\TestCase;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Contracts\JobRepository;

class PerformNowTest extends TestCase
{
    public function test_it_moves_a_delayed_job_onto_the_ready_queue()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->firstDelayedJobId();

        $this->postJson("horizon/delayed-jobs/perform/{$id}")
            ->assertOk()
            ->assertJson(['performed' => true]);

        [$redis, $keys] = $this->keys('default');

        $this->assertSame(0, $redis->zcard($keys['delayed']));
        $this->assertSame(1, $redis->llen($keys['ready']));
        $this->assertSame(1, $redis->llen($keys['notify']));

        // The payload is untouched, so the job runs on the attempt it was on.
        $payload = json_decode($redis->lindex($keys['ready'], 0), true);
        $this->assertSame($id, $payload['uuid']);
    }

    public function test_it_tells_horizon_the_job_is_pending_again()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->firstDelayedJobId();

        $this->postJson("horizon/delayed-jobs/perform/{$id}")->assertOk();

        $job = app(JobRepository::class)->getJobs([$id])->first();

        $this->assertSame('pending', $job->status);
    }

    public function test_the_promoted_job_can_then_be_popped()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->firstDelayedJobId();

        $this->postJson("horizon/delayed-jobs/perform/{$id}")->assertOk();

        $popped = Queue::connection('redis')->pop('default');

        $this->assertNotNull($popped);
        $this->assertSame($id, $popped->uuid());
    }

    public function test_it_finds_a_job_on_a_queue_the_hint_did_not_name()
    {
        Queue::connection('redis')->later(3600, new OtherJob, null, 'emails');

        $id = $this->firstDelayedJobId();

        // A stale hint must fall back to a search rather than report the job gone.
        $this->postJson("horizon/delayed-jobs/perform/{$id}", [
            'connection' => 'redis',
            'queue' => 'default',
        ])->assertOk()->assertJson(['performed' => true]);

        [$redis, $keys] = $this->keys('emails');

        $this->assertSame(1, $redis->llen($keys['ready']));
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

    public function test_many_jobs_can_carry_their_queue_hints()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');
        Queue::connection('redis')->later(3600, new OtherJob, null, 'emails');

        $jobs = collect($this->getJson('horizon/delayed-jobs?type=')->json('jobs'))
            ->map(fn ($job) => ['id' => $job['id'], 'connection' => $job['connection'], 'queue' => $job['queue']])
            ->all();

        $response = $this->postJson('horizon/delayed-jobs/perform', ['ids' => $jobs])->assertOk();

        $this->assertSame(2, $response->json('count'));
        $this->assertSame(0, $this->getJson('horizon/delayed-jobs?type=')->json('total'));
    }

    public function test_promoting_the_same_job_twice_only_queues_it_once()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->firstDelayedJobId();

        $this->postJson("horizon/delayed-jobs/perform/{$id}")->assertOk();
        $this->postJson("horizon/delayed-jobs/perform/{$id}")->assertNotFound();

        [$redis, $keys] = $this->keys('default');

        $this->assertSame(1, $redis->llen($keys['ready']));
    }

    public function test_a_malformed_member_does_not_stop_the_others()
    {
        Queue::connection('redis')->later(3600, new ExampleJob(1), null, 'default');

        $id = $this->firstDelayedJobId();

        [$redis, $keys] = $this->keys('default');

        // Not JSON, but holding the id, so it passes the substring test and
        // reaches the decode.
        $redis->zadd($keys['delayed'], 1, "not json {$id}");

        $response = $this->postJson('horizon/delayed-jobs/perform', ['ids' => [$id]])->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame(1, $redis->llen($keys['ready']));
        $this->assertSame(1, $redis->zcard($keys['delayed']));
    }

    public function test_one_pass_promotes_several_jobs_on_one_queue()
    {
        foreach (range(1, 3) as $i) {
            Queue::connection('redis')->later(3600, new ExampleJob($i), null, 'default');
        }

        Queue::connection('redis')->later(3600, new ExampleJob(4), null, 'default');

        $ids = array_slice($this->getJson('horizon/delayed-jobs?type=')->json('jobs.*.id'), 0, 3);

        $response = $this->postJson('horizon/delayed-jobs/perform', ['ids' => $ids])->assertOk();

        $this->assertSame($ids, $response->json('performed'));

        [$redis, $keys] = $this->keys('default');

        $this->assertSame(3, $redis->llen($keys['ready']));
        $this->assertSame(1, $redis->zcard($keys['delayed']));
    }

    public function test_more_ids_than_a_page_holds_are_refused()
    {
        config(['horizon-delayed-jobs.per_page' => 2]);

        $this->postJson('horizon/delayed-jobs/perform', ['ids' => ['a', 'b', 'c']])
            ->assertStatus(422);
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
     * @return array{0: PhpRedisConnection|PredisConnection, 1: array{ready: string, delayed: string, notify: string}}
     */
    protected function keys(string $queue)
    {
        return app(QueueKeys::class)->resolve('redis', $queue);
    }
}
