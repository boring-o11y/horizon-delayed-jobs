# Horizon Delayed Jobs

A **Retries** page for the Laravel Horizon dashboard: the jobs sitting on a backoff or a schedule, and a button to run one now instead of waiting.

Horizon shows you jobs that are pending, completed and failed. It does not show you the ones in between — a job released with a five minute backoff is invisible until it comes back. This adds a page for them.

```
Retries                      [Retries] [Scheduled] [All]  [queue ▾]  [filter]  [Run 2 now]

☑  App\Jobs\SendInvoice          default   1 / 3    in 4m 12s     [Run now]
   9f2a1c4e-...
☑  App\Jobs\SyncCustomer         default   2 / 5    in 1m 03s     [Run now]
☐  App\Jobs\GenerateReport       reports   0        in 2h 14m     [Run now]
```

## Install

```bash
composer require boring-o11y/horizon-delayed-jobs
```

That is the whole installation. A **Retries** link appears in Horizon's sidebar.

To change anything:

```bash
php artisan vendor:publish --tag=horizon-delayed-jobs-config
```

## How it reads the jobs

There is no new index and no listener. The framework's own `queues:{name}:delayed` sorted set — the one a worker migrates from when a backoff expires — *is* the list, and its score is the timestamp each job becomes available. That is also the order the page wants, so the read is a single `ZRANGE` per queue.

Nothing is written on the dispatch or worker path, nothing has to be trimmed, and the page cannot drift out of step with the queue, because it is reading the queue.

Two consequences worth knowing:

- **Retries and scheduled jobs are told apart by `attempts`.** The pop script increments a job's attempts as it hands it to a worker, so anything back in the delayed set having already been attempted was released rather than scheduled. That is what the Retries / Scheduled tabs split on.
- **The last exception is not shown.** That lives in Horizon's job hash, not the payload. The page tells you what is waiting and when it runs, not why it failed last time — follow the job id into Horizon's own job view for that.

## Run now

Pressing **Run now** moves the job out of the delayed set onto the tail of its ready queue and pushes the queue's notify list, which is exactly what a worker's own migration does when the backoff expires — just early. The payload is untouched, so the job runs on the attempt it was already on, with its `retryUntil` intact.

The move is a single Lua script, so two people pressing the button at the same moment, or a worker's migration landing in between, cannot run the job twice: only the caller whose `ZREM` removed the member gets as far as the `RPUSH`. Horizon is then told the job is pending again, the same call Horizon makes for a job its own worker migrated.

A job that is no longer delayed — the backoff expired while the page was open — comes back as a 404 and the page says so, rather than reporting a success that did nothing.

Set `perform_now` to `false` for a read-only page; the routes are not registered at all.

## Which queues are covered

By default: every queue named by a running Horizon supervisor, plus every queue named by a supervisor in `config/horizon.php`. Reading the config as well as the runtime means the page still works with Horizon stopped.

A job delayed onto a queue no supervisor processes is not listed — nothing would run it anyway. Name it explicitly if you want it shown:

```php
'queues' => ['redis' => ['default', 'unwatched']],
```

## How the page gets into the dashboard

Horizon's dashboard is a compiled Vue bundle inlined into one Blade layout. There is no route to register, no screen to add, no asset hook. So this package takes over `horizon::layout`, renders Horizon's *real* layout out of an aliased `horizon-original` namespace, and splices three things into the result:

| Anchor in Horizon's layout | What goes in |
|---|---|
| `<router-view></router-view>` | the page's mount point |
| `<ul class="nav flex-column">` | the sidebar link |
| `</body>` | this package's script and styles |

The mount lands inside `#horizon`, which Vue uses as its in-DOM template, so it compiles to a static node Vue renders once and never patches — far safer than inserting into Vue-owned DOM after mount. And because Horizon's router has no route for `/retries`, it renders an empty outlet there and this page is the only content in the column.

**Every splice is optional.** Horizon can change its markup in any release; a missing anchor logs a warning naming what the dashboard will be without, and leaves the rest alone. A dashboard short one sidebar link beats a dashboard that will not render. A scheduled canary job runs the suite against Horizon's development branch so drift shows up here before it shows up for you.

## Configuration

| Key | Default | |
|---|---|---|
| `enabled` | `true` | Turn the package off entirely: no routes, no view override, no link |
| `path` | `retries` | The dashboard-relative path the page answers to |
| `label` | `Retries` | The sidebar label |
| `queues` | `null` | An explicit `connection => queues` map, instead of discovery |
| `scan_limit` | `1000` | How many entries to read per queue per request |
| `per_page` | `50` | Rows per page |
| `poll_interval` | `5000` | Refresh interval in milliseconds |
| `perform_now` | `true` | Whether the page may promote jobs |

### On `scan_limit`

Filtering and sorting happen over what is read, so a queue holding more delayed jobs than the limit is reported as **truncated** in the UI rather than quietly losing its tail. The count in the header stays exact either way — it comes from `ZCARD`, not from what was read.

## Compatibility

PHP 8.1+, Laravel 10/11/12, Horizon 5.24+. Both `phpredis` and `predis` are supported and the suite runs against each; a Redis key prefix is covered by its own tests, because a package that read the queue keys one way and wrote them another would show jobs whose "Run now" silently did nothing.

The page and the promotion both address the queue keys through Lua, the way the framework's own `migrate()` and `size()` do, which is what keeps them in agreement.

## Testing

There is no local PHP toolchain requirement — everything runs in Docker:

```bash
docker compose run --rm app composer install
docker compose run --rm app vendor/bin/phpunit
docker compose run --rm app env REDIS_CLIENT=predis vendor/bin/phpunit
```

## Credits

The layout-override technique is the one [knobik/laravel-horizon-job-output](https://github.com/knobik/laravel-horizon-job-output) uses, and the promotion script is adapted from Laravel Horizon's own delayed job handling. Both MIT.

Originally done as part of [Skyline](https://boring-observability.dev/skyline). For more job controls and visibility into Horizon queues, check out [Skyline](https://boring-observability.dev/skyline) - a drop-in replacement for Laravel Horizon.

## License

MIT. See [LICENSE.md](LICENSE.md).
