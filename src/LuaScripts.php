<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use RuntimeException;

class LuaScripts
{
    /**
     * Run a script, turning a failed one into an exception.
     *
     * Predis throws when a script errors, but phpredis returns false and keeps
     * the error to itself, which would make a broken read look like an empty
     * queue. Every script here returns a table, so false is never an answer.
     *
     * @param  PhpRedisConnection|PredisConnection  $redis
     * @param  string  $script
     * @param  int  $numberOfKeys
     * @param  mixed  ...$arguments
     * @return array<int, mixed>
     */
    public static function run($redis, $script, $numberOfKeys, ...$arguments)
    {
        $result = $redis->eval($script, $numberOfKeys, ...$arguments);

        if (! is_array($result)) {
            $client = $redis->client();
            $error = is_object($client) && method_exists($client, 'getLastError') ? $client->getLastError() : null;

            throw new RuntimeException('A horizon-delayed-jobs Redis script failed: ' . ($error ?: 'no reply'));
        }

        return $result;
    }

    /**
     * Get the Lua script that promotes delayed jobs onto their ready queue.
     *
     * The delayed set is keyed by payload, not by job id, so the jobs have to
     * be found by scanning. Every id is looked for in the same single pass, so
     * promoting fifty jobs costs one walk of the set rather than fifty, and the
     * walk stops as soon as the last id is found. Doing the find, the removal
     * and the push in one script is what makes the promotion safe: two
     * dashboards pressing the button at the same moment, or a worker's own
     * migration landing in between, cannot run a job twice, because only the
     * caller whose ZREM removed the member gets as far as the RPUSH.
     *
     * A member that is not valid JSON is skipped rather than allowed to abort
     * the script, so one bad entry cannot stop the others being promoted.
     *
     * The notify list is pushed too, which is what wakes a worker that is
     * blocking on BLPOP rather than polling.
     *
     * KEYS[1] - The queue's delayed sorted set
     * KEYS[2] - The queue's ready list
     * KEYS[3] - The queue's notification list
     * ARGV    - The ids of the jobs to promote
     *
     * Returns a flat list of id, payload pairs for the jobs it promoted.
     *
     * Adapted from Laravel Horizon's own delayed job handling (MIT).
     *
     * @return string
     */
    public static function performDelayed()
    {
        return <<<'LUA'
            local wanted = {}
            local remaining = #ARGV
            local promoted = {}

            for _, id in ipairs(ARGV) do
                wanted[id] = true
            end

            local cursor = "0"

            repeat
                local result = redis.call('zscan', KEYS[1], cursor, 'COUNT', 100)
                cursor = result[1]
                local entries = result[2]

                for i = 1, #entries, 2 do
                    local payload = entries[i]

                    -- A plain substring test first, so only the payloads that
                    -- could hold one of the ids pay for a full decode.
                    for id in pairs(wanted) do
                        if string.find(payload, id, 1, true) then
                            local ok, decoded = pcall(cjson.decode, payload)

                            if ok and type(decoded) == 'table' then
                                local match = nil

                                if wanted[decoded['id']] then
                                    match = decoded['id']
                                elseif wanted[decoded['uuid']] then
                                    match = decoded['uuid']
                                end

                                if match then
                                    wanted[match] = nil
                                    remaining = remaining - 1

                                    if redis.call('zrem', KEYS[1], payload) == 1 then
                                        redis.call('rpush', KEYS[2], payload)
                                        redis.call('rpush', KEYS[3], 1)

                                        table.insert(promoted, match)
                                        table.insert(promoted, payload)
                                    end
                                end
                            end

                            break
                        end
                    end

                    if remaining == 0 then
                        return promoted
                    end
                end
            until cursor == "0"

            return promoted
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
     * ARGV[1] - How many entries to read; 0 only counts them
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
