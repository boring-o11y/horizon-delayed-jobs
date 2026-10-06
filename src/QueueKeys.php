<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;

/**
 * Resolves the Redis keys and connection behind a queue.
 *
 * Only Redis queues have a delayed sorted set to read, so anything else is
 * reported as unresolvable and skipped rather than guessed at.
 */
class QueueKeys
{
    public function __construct(protected QueueFactory $queue) {}

    /**
     * Resolve a connection/queue pair to its Redis connection and base key.
     *
     * The base key comes from the framework so that Redis Cluster keeps
     * working: newer versions wrap the queue name in a hash tag there, which
     * is what puts a queue's ready, delayed and notify keys in one slot and
     * lets a single script touch all three.
     *
     * The connection is typed as one of the framework's two drivers because
     * both normalise eval() to (script, numkeys, ...args); the base class only
     * advertises phpredis's native (script, args, numkeys) signature.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @return array{0: PhpRedisConnection|PredisConnection, 1: string}|null
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

        /** @var PhpRedisConnection|PredisConnection $redis */
        $redis = $instance->getConnection();

        return [$redis, $key];
    }

    /**
     * Resolve a queue connection by name, swallowing an unknown name.
     *
     * @param  string  $connection
     * @return Queue|null
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
