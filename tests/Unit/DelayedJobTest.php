<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Unit;

use BoringO11y\HorizonDelayedJobs\DelayedJob;
use PHPUnit\Framework\TestCase;

class DelayedJobTest extends TestCase
{
    public function test_it_reads_a_payload()
    {
        $job = DelayedJob::fromPayload(json_encode([
            'uuid' => 'abc-123',
            'displayName' => 'App\\Jobs\\SendInvoice',
            'attempts' => 2,
            'maxTries' => 5,
            'pushedAt' => 1700000000.5,
            'retryUntil' => 1700003600,
        ]), 1700000600.0, 'redis', 'default');

        $this->assertSame('abc-123', $job->id);
        $this->assertSame('App\\Jobs\\SendInvoice', $job->name);
        $this->assertSame('redis', $job->connection);
        $this->assertSame('default', $job->queue);
        $this->assertSame(2, $job->attempts);
        $this->assertSame(5, $job->maxTries);
        $this->assertSame(1700000600.0, $job->availableAt);
        $this->assertSame(1700003600, $job->retryUntil);
    }

    public function test_it_falls_back_to_the_id_when_there_is_no_uuid()
    {
        $job = DelayedJob::fromPayload(json_encode([
            'id' => 'legacy-id',
            'displayName' => 'App\\Jobs\\Thing',
        ]), 1.0, 'redis', 'default');

        $this->assertSame('legacy-id', $job->id);
    }

    public function test_it_rejects_payloads_it_cannot_identify()
    {
        $this->assertNull(DelayedJob::fromPayload('not json', 1.0, 'redis', 'default'));
        $this->assertNull(DelayedJob::fromPayload(json_encode(['displayName' => 'X']), 1.0, 'redis', 'default'));
    }

    public function test_an_attempted_job_is_a_retry()
    {
        $scheduled = new DelayedJob('a', 'X', 'redis', 'default', 0, null, 1.0);
        $retrying = new DelayedJob('b', 'X', 'redis', 'default', 1, null, 1.0);

        $this->assertFalse($scheduled->isRetry());
        $this->assertTrue($retrying->isRetry());
    }

    public function test_the_countdown_never_goes_negative()
    {
        $overdue = new DelayedJob('a', 'X', 'redis', 'default', 0, null, microtime(true) - 500);

        $this->assertSame(0, $overdue->secondsRemaining());

        $upcoming = new DelayedJob('b', 'X', 'redis', 'default', 0, null, microtime(true) + 30);

        $this->assertGreaterThan(25, $upcoming->secondsRemaining());
    }
}
