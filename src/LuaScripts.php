<?php

namespace BoringO11y\HorizonDelayedJobs;

class LuaScripts
{
    /**
     * Get the Lua script that promotes one delayed job onto its ready queue.
     *
     * The delayed set is keyed by payload, not by job id, so the job has to be
     * found by scanning. Doing the find, the removal and the push in one script
     * is what makes the promotion safe: two dashboards pressing the button at
     * the same moment, or a worker's own migration landing in between, cannot
     * run the job twice, because only the caller whose ZREM removed the member
     * gets as far as the RPUSH.
     *
     * The notify list is pushed too, which is what wakes a worker that is
     * blocking on BLPOP rather than polling.
     *
     * KEYS[1] - The queue's delayed sorted set
     * KEYS[2] - The queue's ready list
     * KEYS[3] - The queue's notification list
     * ARGV[1] - The id of the job to promote
     *
     * Adapted from Laravel Horizon's own delayed job handling (MIT).
     *
     * @return string
     */
    public static function performDelayed()
    {
        return <<<'LUA'
            local cursor = "0"

            repeat
                local result = redis.call('zscan', KEYS[1], cursor, 'COUNT', 100)
                cursor = result[1]
                local entries = result[2]

                for i = 1, #entries, 2 do
                    local payload = entries[i]
                    local decoded = cjson.decode(payload)

                    if decoded['id'] == ARGV[1] or decoded['uuid'] == ARGV[1] then
                        if redis.call('zrem', KEYS[1], payload) == 1 then
                            redis.call('rpush', KEYS[2], payload)
                            redis.call('rpush', KEYS[3], 1)

                            return payload
                        end

                        return false
                    end
                end
            until cursor == "0"

            return false
LUA;
    }

    /**
     * Get the Lua script that reads one page of a queue's delayed set.
     *
     * The read goes through a script for the same reason the promotion does:
     * both then address the queue keys exactly the way the framework's own
     * migrate() and size() do, so a Redis prefix, or a client that shapes
     * WITHSCORES replies differently, cannot make the listing and the button
     * disagree about which key they are talking about.
     *
     * KEYS[1] - The queue's delayed sorted set
     * ARGV[1] - How many entries to read
     *
     * @return string
     */
    public static function readDelayed()
    {
        return <<<'LUA'
            local total = redis.call('zcard', KEYS[1])
            local limit = tonumber(ARGV[1])

            if limit < 1 then
                return {total, {}}
            end

            return {total, redis.call('zrange', KEYS[1], 0, limit - 1, 'WITHSCORES')}
LUA;
    }
}
