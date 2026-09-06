<?php

namespace BoringO11y\HorizonDelayedJobs;

use JsonSerializable;

/**
 * One entry of a queue's delayed sorted set.
 *
 * Everything here is read out of the raw payload the framework stored, plus
 * the sorted set score. Nothing is read from Horizon's own job hash, so the
 * listing works whether or not Horizon has ever seen the job.
 */
class DelayedJob implements JsonSerializable
{
    /**
     * @param  string  $id  The job's UUID, which is also its Horizon job id.
     * @param  string  $name  The display name, i.e. the job class.
     * @param  string  $connection  The queue connection the job is delayed on.
     * @param  string  $queue  The queue the job is delayed on.
     * @param  int  $attempts  How many times the job has been picked up already.
     * @param  int|null  $maxTries  The job's configured attempt limit, if it has one.
     * @param  float  $availableAt  The Unix timestamp the job becomes available at.
     * @param  float|null  $pushedAt  When Horizon first pushed the job, if it stamped it.
     * @param  int|null  $retryUntil  The timestamp past which the job will not be retried.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $connection,
        public string $queue,
        public int $attempts,
        public ?int $maxTries,
        public float $availableAt,
        public ?float $pushedAt = null,
        public ?int $retryUntil = null,
    ) {
    }

    /**
     * Build a job from a delayed set member and its score.
     *
     * The score the framework writes is the availability timestamp, which is
     * the one piece of information the payload itself does not carry.
     *
     * @param  string  $payload  The raw JSON payload stored in the sorted set.
     * @param  float  $score  The sorted set score, i.e. availableAt.
     * @param  string  $connection
     * @param  string  $queue
     * @return static|null  Null when the payload is not decodable job JSON.
     */
    public static function fromPayload($payload, $score, $connection, $queue)
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        // Horizon sets payload['id'] to the UUID, but a job pushed by something
        // other than Horizon's queue may only carry one of the two.
        $id = $decoded['uuid'] ?? $decoded['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        return new static(
            id: $id,
            name: (string) ($decoded['displayName'] ?? $decoded['job'] ?? 'Unknown'),
            connection: $connection,
            queue: $queue,
            attempts: (int) ($decoded['attempts'] ?? 0),
            maxTries: isset($decoded['maxTries']) ? (int) $decoded['maxTries'] : null,
            availableAt: (float) $score,
            pushedAt: isset($decoded['pushedAt']) ? (float) $decoded['pushedAt'] : null,
            retryUntil: isset($decoded['retryUntil']) ? (int) $decoded['retryUntil'] : null,
        );
    }

    /**
     * Determine whether the job is waiting on a retry backoff.
     *
     * A job's attempts counter is incremented by the pop script as it is
     * handed to a worker, so anything back in the delayed set having already
     * been attempted was released rather than scheduled.
     *
     * @return bool
     */
    public function isRetry()
    {
        return $this->attempts >= 1;
    }

    /**
     * Get the seconds remaining until the job becomes available.
     *
     * Negative values are clamped: a job whose time has come is waiting on the
     * next worker loop to migrate it, not on the clock.
     *
     * @return int
     */
    public function secondsRemaining()
    {
        return (int) max(0, ceil($this->availableAt - microtime(true)));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'attempts' => $this->attempts,
            'max_tries' => $this->maxTries,
            'available_at' => $this->availableAt,
            'seconds_remaining' => $this->secondsRemaining(),
            'pushed_at' => $this->pushedAt,
            'retry_until' => $this->retryUntil,
            'is_retry' => $this->isRetry(),
        ];
    }
}
