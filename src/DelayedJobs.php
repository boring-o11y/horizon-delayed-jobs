<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Collection;

/**
 * Reads the jobs currently waiting on a delay.
 *
 * The framework's own "{queue}:delayed" sorted set is the source: it is the
 * thing a worker migrates from, so it cannot drift the way a mirrored index
 * would, and reading it needs no listener, no write path and no cleanup. Its
 * score is the timestamp the job becomes available, which is also the order
 * the page wants — next to run, first.
 */
class DelayedJobs
{
    public function __construct(
        protected Queues $queues,
        protected QueueKeys $keys,
        protected Config $config,
    ) {
    }

    /**
     * Get a page of delayed jobs across every known queue.
     *
     * @param  array{type?: string|null, search?: string|null, queue?: string|null, page?: int, per_page?: int}  $options
     * @return array{
     *     jobs: \Illuminate\Support\Collection<int, \BoringO11y\HorizonDelayedJobs\DelayedJob>,
     *     total: int,
     *     matching: int,
     *     truncated: bool,
     *     page: int,
     *     per_page: int
     * }
     */
    public function paginate(array $options = [])
    {
        $perPage = max(1, (int) ($options['per_page'] ?? $this->config->get('horizon-delayed-jobs.per_page', 50)));
        $page = max(1, (int) ($options['page'] ?? 1));

        [$jobs, $total, $truncated] = $this->read();

        $matched = $this->filter($jobs, $options)
            ->sortBy(fn (DelayedJob $job) => $job->availableAt)
            ->values();

        return [
            'jobs' => $matched->slice(($page - 1) * $perPage, $perPage)->values(),
            'total' => $total,
            'matching' => $matched->count(),
            'truncated' => $truncated,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Read every known queue's delayed set.
     *
     * Each queue is read up to the scan limit. The totals come back from the
     * same script as the entries, so a queue holding more than the limit is
     * reported as truncated rather than quietly losing its tail: the count in
     * the header stays honest even when the table cannot show everything.
     *
     * @return array{0: \Illuminate\Support\Collection<int, \BoringO11y\HorizonDelayedJobs\DelayedJob>, 1: int, 2: bool}
     */
    protected function read()
    {
        $limit = max(1, (int) $this->config->get('horizon-delayed-jobs.scan_limit', 1000));

        $jobs = collect();
        $total = 0;
        $truncated = false;

        foreach ($this->queues->all() as [$connection, $queue]) {
            $resolved = $this->keys->resolve($connection, $queue);

            if ($resolved === null) {
                continue;
            }

            [$redis, $key] = $resolved;

            $result = $redis->eval(LuaScripts::readDelayed(), 1, $key.':delayed', $limit);

            $count = (int) ($result[0] ?? 0);
            $total += $count;
            $truncated = $truncated || $count > $limit;

            foreach ($this->pairs($result[1] ?? []) as [$payload, $score]) {
                if ($job = DelayedJob::fromPayload($payload, $score, $connection, $queue)) {
                    $jobs->push($job);
                }
            }
        }

        return [$jobs, $total, $truncated];
    }

    /**
     * Walk a flat WITHSCORES reply as member/score pairs.
     *
     * @param  array<int, string>  $entries
     * @return \Generator<int, array{0: string, 1: float}>
     */
    protected function pairs($entries)
    {
        $entries = array_values((array) $entries);

        for ($i = 0; $i + 1 < count($entries); $i += 2) {
            yield [$entries[$i], (float) $entries[$i + 1]];
        }
    }

    /**
     * Apply the listing's filters.
     *
     * @param  \Illuminate\Support\Collection<int, \BoringO11y\HorizonDelayedJobs\DelayedJob>  $jobs
     * @param  array<string, mixed>  $options
     * @return \Illuminate\Support\Collection<int, \BoringO11y\HorizonDelayedJobs\DelayedJob>
     */
    protected function filter(Collection $jobs, array $options)
    {
        $type = $options['type'] ?? null;
        $queue = $options['queue'] ?? null;
        $search = trim((string) ($options['search'] ?? ''));

        return $jobs->filter(function (DelayedJob $job) use ($type, $queue, $search) {
            if ($type === 'retries' && ! $job->isRetry()) {
                return false;
            }

            if ($type === 'scheduled' && $job->isRetry()) {
                return false;
            }

            if ($queue !== null && $queue !== '' && $job->queue !== $queue) {
                return false;
            }

            if ($search !== '' && stripos($job->name, $search) === false) {
                return false;
            }

            return true;
        });
    }

    /**
     * Get the queues the listing covers, for the UI's queue filter.
     *
     * @return array<int, string>
     */
    public function queueNames()
    {
        return $this->queues->all()
            ->map(fn ($pair) => $pair[1])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
