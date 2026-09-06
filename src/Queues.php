<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Throwable;

/**
 * Works out which connection/queue pairs to look for delayed jobs on.
 *
 * There is no index of "queues that exist" in Redis — a queue is just whatever
 * keys someone happened to push to — so the set has to be assembled from what
 * Horizon is configured to work on. Running supervisors are the most accurate
 * source, config is the one that still answers when Horizon is stopped, and
 * between them they cover every queue a Horizon dashboard is about.
 */
class Queues
{
    public function __construct(
        protected Application $app,
        protected Config $config,
    ) {
    }

    /**
     * Get the queues to scan as a list of [connection, queue] pairs.
     *
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string}>
     */
    public function all()
    {
        if ($configured = $this->config->get('horizon-delayed-jobs.queues')) {
            return $this->fromMap($configured);
        }

        $pairs = $this->fromSupervisors()
            ->merge($this->fromConfiguredSupervisors());

        if ($pairs->isEmpty()) {
            $pairs = $this->fallback();
        }

        return $pairs->unique(fn ($pair) => $pair[0].'|'.$pair[1])->values();
    }

    /**
     * Build the pairs from an explicit connection => queues map.
     *
     * @param  array<string, array<int, string>|string>  $map
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string}>
     */
    protected function fromMap(array $map)
    {
        return collect($map)
            ->flatMap(fn ($queues, $connection) => collect((array) $queues)
                ->map(fn ($queue) => [(string) $connection, (string) $queue]))
            ->unique(fn ($pair) => $pair[0].'|'.$pair[1])
            ->values();
    }

    /**
     * Build the pairs from the supervisors Horizon currently has running.
     *
     * Horizon is not required to be running for the page to work, so a failure
     * to reach the supervisor repository is not an error here — the configured
     * supervisors below still describe the same queues.
     *
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string}>
     */
    protected function fromSupervisors()
    {
        try {
            $supervisors = $this->app->make(SupervisorRepository::class)->all();
        } catch (Throwable) {
            return collect();
        }

        return collect($supervisors)->flatMap(function ($supervisor) {
            $options = (array) ($supervisor->options ?? []);

            return $this->expand(
                $options['connection'] ?? null,
                $options['queue'] ?? null
            );
        });
    }

    /**
     * Build the pairs from config/horizon.php.
     *
     * Every environment's supervisors are read, not just the current one: the
     * dashboard is frequently opened against an environment whose workers run
     * elsewhere, and a queue listed under any of them is a queue whose delayed
     * jobs are worth showing.
     *
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string}>
     */
    protected function fromConfiguredSupervisors()
    {
        $defaults = (array) $this->config->get('horizon.defaults', []);

        return collect($this->config->get('horizon.environments', []))
            ->flatMap(fn ($supervisors) => collect((array) $supervisors)
                ->map(fn ($supervisor, $name) => array_merge(
                    (array) ($defaults[$name] ?? []), (array) $supervisor
                )))
            ->flatMap(fn ($supervisor) => $this->expand(
                $supervisor['connection'] ?? null,
                $supervisor['queue'] ?? null
            ));
    }

    /**
     * Fall back to the application's default queue connection and queue.
     *
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string}>
     */
    protected function fallback()
    {
        $connection = (string) $this->config->get('queue.default');

        if ($connection === '') {
            return collect();
        }

        return collect([[
            $connection,
            (string) $this->config->get("queue.connections.{$connection}.queue", 'default'),
        ]]);
    }

    /**
     * Expand one supervisor's connection and queue into pairs.
     *
     * A supervisor's queue may be an array, or the comma separated string the
     * worker command takes; both mean "these queues, in this order".
     *
     * @param  string|null  $connection
     * @param  array<int, string>|string|null  $queue
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string}>
     */
    protected function expand($connection, $queue)
    {
        if (! is_string($connection) || $connection === '' || $queue === null) {
            return collect();
        }

        return collect(is_array($queue) ? $queue : explode(',', (string) $queue))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->map(fn ($name) => [$connection, $name])
            ->values();
    }
}
