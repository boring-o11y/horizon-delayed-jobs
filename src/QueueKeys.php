<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;

/**
 * Resolves the Redis keys and connection behind a queue.
 *
 * Only Redis queues have a delayed sorted set to read, so anything else is
 * reported as unresolvable and skipped rather than guessed at.
 */
class QueueKeys
{
    public function __construct(protected QueueFactory $queue)
    {
    }

    /**
     * Resolve a connection/queue pair to its Redis connection and base key.
     *
     * The base key comes from the framework so that Redis Cluster keeps
     * working: newer versions wrap the queue name in a hash tag there, which
     * is what puts a queue's ready, delayed and notify keys in one slot and
     * lets a single script touch all three.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @return array{0: \Illuminate\Redis\Connections\Connection, 1: string}|null
     */
    public function resolve($connection, $queue)
    {
        $instance = $this->connection($connection);

        if (! $instance instanceof RedisQueue) {
            return null;
        }

        $key = method_exists($instance, 'getQueueRedisKey')
            ? $instance->getQueueRedisKey($queue)
            : $instance->getQueue($queue);

        return [$instance->getConnection(), $key];
    }

    /**
     * Resolve a queue connection by name, swallowing an unknown name.
     *
     * @param  string  $connection
     * @return \Illuminate\Contracts\Queue\Queue|null
     */
    protected function connection($connection)
    {
        try {
            return $this->queue->connection($connection);
        } catch (\Throwable) {
            return null;
        }
    }
}
