<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\JobsMigrated;
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
        protected Dispatcher $events,
    ) {}

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
     * @return bool Whether the job was found and promoted.
     */
    public function perform($id, $connection = null, $queue = null)
    {
        return $this->performMany([compact('id', 'connection', 'queue')]) !== [];
    }

    /**
     * Promote each of the given jobs.
     *
     * Each job is an id, or an array of the id with its connection and queue
     * hints from the listing.
     *
     * Ids are looked for a queue at a time, all of them in one pass of that
     * queue's delayed set: first on the queues their hints name, then whatever
     * is still missing on every queue in turn, stopping once nothing is. So
     * however many ids are sent, no delayed set is walked more than twice.
     *
     * @param  array<int, mixed>  $jobs
     * @return array<int, string> The ids that were promoted.
     */
    public function performMany(array $jobs)
    {
        $jobs = collect($jobs)
            ->map(fn ($job) => is_array($job) ? $job : ['id' => $job])
            ->filter(fn ($job) => is_string($job['id'] ?? null) && $job['id'] !== '')
            ->unique('id')
            ->values();

        $promoted = [];

        $hinted = $jobs
            ->filter(fn ($job) => $this->hint($job) !== null)
            ->groupBy(fn ($job) => implode('|', $this->hint($job)));

        foreach ($hinted as $group) {
            [$connection, $queue] = $this->hint($group->first());

            $promoted = array_merge($promoted, $this->promote($group->pluck('id')->all(), $connection, $queue));
        }

        foreach ($this->queues->all() as [$connection, $queue]) {
            $missing = $jobs->pluck('id')->diff($promoted)->values()->all();

            if ($missing === []) {
                break;
            }

            $promoted = array_merge($promoted, $this->promote($missing, $connection, $queue));
        }

        // Reported in the order they were asked for.
        return $jobs->pluck('id')->intersect($promoted)->values()->all();
    }

    /**
     * The connection and queue a job's hint names, if it names both.
     *
     * @param  array<string, mixed>  $job
     * @return array{0: string, 1: string}|null
     */
    protected function hint(array $job)
    {
        $connection = $job['connection'] ?? null;
        $queue = $job['queue'] ?? null;

        if (! is_string($connection) || ! is_string($queue) || $connection === '' || $queue === '') {
            return null;
        }

        return [$connection, $queue];
    }

    /**
     * Try to promote the jobs on one specific queue.
     *
     * @param  array<int, string>  $ids
     * @param  string  $connection
     * @param  string  $queue
     * @return array<int, string> The ids that were promoted.
     */
    protected function promote(array $ids, $connection, $queue)
    {
        $resolved = $this->keys->resolve($connection, $queue);

        if ($resolved === null || $ids === []) {
            return [];
        }

        [$redis, $keys] = $resolved;

        $result = LuaScripts::run(
            $redis,
            LuaScripts::performDelayed(),
            3,
            $keys['delayed'],
            $keys['ready'],
            $keys['notify'],
            ...$ids
        );

        $promoted = [];

        for ($i = 0; $i + 1 < count($result); $i += 2) {
            $promoted[] = (string) $result[$i];

            $this->recordMigration($connection, $queue, (string) $result[$i + 1]);
        }

        return $promoted;
    }

    /**
     * Tell Horizon the job is back on the ready queue.
     *
     * This fires the same event Horizon fires for a job its own worker
     * migrates, and it is what moves the job off the dashboard's delayed badge.
     * It is also bookkeeping: the job is already queued by the time we get
     * here, so a failure to record it must not be reported as a failure to run
     * it.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @return void
     */
    protected function recordMigration($connection, $queue, $payload)
    {
        try {
            $this->events->dispatch(
                (new JobsMigrated([$payload]))->connection($connection)->queue($queue)
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
