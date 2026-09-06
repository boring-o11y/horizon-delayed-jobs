<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\JobPayload;
use Throwable;

/**
 * Promotes a delayed job onto its ready queue so a worker picks it up now.
 *
 * This is the same move a worker's own migration makes when the backoff
 * expires, made early and for one job: the entry leaves the delayed set and
 * joins the tail of the ready list, and the queue's notify list is pushed so a
 * blocked worker wakes for it. Nothing about the payload changes, so the job
 * runs on the attempt it was already on.
 */
class PerformNow
{
    public function __construct(
        protected Queues $queues,
        protected QueueKeys $keys,
        protected Container $container,
    ) {
    }

    /**
     * Promote the given job, searching the queues it might be delayed on.
     *
     * The connection and queue are hints from the listing that made the button.
     * They are only ever used to look in the right place first — a stale hint
     * falls back to a full search rather than reporting the job as gone.
     *
     * @param  string  $id
     * @param  string|null  $connection
     * @param  string|null  $queue
     * @return bool  Whether the job was found and promoted.
     */
    public function perform($id, $connection = null, $queue = null)
    {
        foreach ($this->candidates($connection, $queue) as [$onConnection, $onQueue]) {
            if ($this->promote($id, $onConnection, $onQueue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Promote each of the given jobs.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>  The ids that were promoted.
     */
    public function performMany(array $ids)
    {
        return collect($ids)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->filter(fn ($id) => $this->perform($id))
            ->values()
            ->all();
    }

    /**
     * Order the queues to search, most likely first.
     *
     * @param  string|null  $connection
     * @param  string|null  $queue
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string}>
     */
    protected function candidates($connection, $queue)
    {
        $all = $this->queues->all();

        if (! $connection || ! $queue) {
            return $all;
        }

        $hinted = [(string) $connection, (string) $queue];

        return collect([$hinted])->concat(
            $all->reject(fn ($pair) => $pair === $hinted)
        );
    }

    /**
     * Try to promote the job on one specific queue.
     *
     * @param  string  $id
     * @param  string  $connection
     * @param  string  $queue
     * @return bool
     */
    protected function promote($id, $connection, $queue)
    {
        $resolved = $this->keys->resolve($connection, $queue);

        if ($resolved === null) {
            return false;
        }

        [$redis, $key] = $resolved;

        $payload = $redis->eval(
            LuaScripts::performDelayed(),
            3,
            $key.':delayed',
            $key,
            $key.':notify',
            $id
        );

        if (! is_string($payload) || $payload === '') {
            return false;
        }

        $this->recordMigration($connection, $queue, $payload);

        return true;
    }

    /**
     * Tell Horizon the job is back on the ready queue.
     *
     * This is exactly what Horizon does for a job its own worker migrates, and
     * it is what moves the job off the dashboard's delayed badge. It is also
     * bookkeeping: the job is already queued by the time we get here, so a
     * failure to record it must not be reported as a failure to run it.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @return void
     */
    protected function recordMigration($connection, $queue, $payload)
    {
        try {
            $this->container->make(JobRepository::class)->migrated(
                $connection, $queue, collect([new JobPayload($payload)])
            );
        } catch (Throwable $e) {
            Log::warning('Promoted a delayed job but could not update its Horizon record.', [
                'connection' => $connection,
                'queue' => $queue,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
