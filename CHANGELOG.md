# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - Unreleased

### Added

- The bulk "Run now" endpoint (`POST {horizon.path}/delayed-jobs/perform`)
  accepts `{id, connection, queue}` objects in `ids`, alongside plain id
  strings. The queue is looked in first, the same way the single-job route
  uses its hints.

### Changed

- Promoting a job now fires Horizon's `JobsMigrated` event, the same event a
  worker fires when a backoff expires, instead of writing to Horizon's job
  repository directly. Listeners your application registers for that event now
  run for "Run now" too.
- The Retries page sends every "Run now", single or bulk, through the bulk
  endpoint with each job's connection and queue. The single-job route is
  unchanged and still available.
- The page stops polling while its browser tab is hidden, and never starts a
  refresh while the previous one is still in flight.
- Page, sidebar and API URLs are generated from the named routes (Horizon's
  `horizon.index` and this package's own) instead of being assembled from
  config.
- The bulk "Run now" endpoint refuses more ids than one page of the listing
  holds (`per_page`) with a 422.

### Performance

- "Run now" for many jobs looks on each job's own queue first, rather than
  searching every queue for every job.
- Finding a job to promote only JSON-decodes the delayed entries that can
  contain its id, instead of every entry in the set.
- With the listing filtered to one queue, the other queues are only counted,
  not read and decoded. The header total is unchanged.
- Queue discovery runs once per listing request instead of twice.
- "Run now" for many jobs walks each queue's delayed set once for all of them,
  instead of once per job.
- An unfiltered listing ("All", no search) reads only as many entries from
  each queue as the requested page needs, instead of up to `scan_limit`.

### Fixed

- A dashboard served from its own `horizon.domain` over plain HTTP no longer
  gets `https://` page and API URLs.
- Non-string query parameters on the listing endpoint (e.g. `search[]=x`) are
  ignored instead of causing an error.
- The view override no longer fails when the application's view finder is not
  Laravel's file-based one; the dashboard renders without the Retries page.
- A route cache built while the package was disabled no longer makes the whole
  Horizon dashboard fail; the Retries page is left out and a warning is logged.
  "Run now" buttons are shown only when their route is actually registered.
- A delayed entry that is not valid JSON no longer aborts "Run now" for every
  other job. A failing Redis script is now reported as an error under phpredis
  too, instead of looking like an empty queue or a job that was already gone.
- Errors from "Run now" are shown instead of being cleared by the refresh that
  follows. The message stays up until you change the filter, queue, page or
  search, or leave the page.
- Leaving the Retries page while it is loading no longer draws the card on the
  Horizon page you moved to.
- Coming back to the Retries page shows the active search in the search box.
- Countdowns no longer jump back up when a checkbox is clicked.

## [0.1.0] - 2026-09-06

Initial release.

[0.2.0]: https://github.com/boring-o11y/horizon-delayed-jobs/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/boring-o11y/horizon-delayed-jobs/releases/tag/v0.1.0
