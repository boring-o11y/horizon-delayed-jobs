<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled the package registers nothing at all: no routes, no view
    | override, no sidebar link. Horizon's dashboard renders exactly as it
    | would without the package installed.
    |
    */

    'enabled' => env('HORIZON_DELAYED_JOBS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Path
    |--------------------------------------------------------------------------
    |
    | The dashboard-relative path the page answers to, and the label used for
    | its sidebar link. The path must be one Horizon's own router does not
    | know about: Horizon then renders an empty <router-view> and this page
    | is the only thing in the column.
    |
    */

    'path' => 'retries',

    'label' => 'Retries',

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | The connection => queues map to read delayed jobs from. When left null
    | the queues are discovered from Horizon's running supervisors and from
    | the supervisor configuration in config/horizon.php, which is what you
    | want unless jobs are delayed onto a queue no supervisor processes.
    |
    |   'queues' => ['redis' => ['default', 'emails']],
    |
    */

    'queues' => null,

    /*
    |--------------------------------------------------------------------------
    | Scan Limit
    |--------------------------------------------------------------------------
    |
    | How many entries to read from each queue's delayed set per request.
    | Filtering and sorting happen over what is read, so a queue holding more
    | delayed jobs than this is reported as truncated in the UI rather than
    | silently paged wrong. Raise it if you routinely sit on deeper backlogs.
    |
    */

    'scan_limit' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | How many rows one page of the listing returns.
    |
    */

    'per_page' => 50,

    /*
    |--------------------------------------------------------------------------
    | Poll Interval
    |--------------------------------------------------------------------------
    |
    | How often, in milliseconds, the page refreshes itself while open.
    |
    */

    'poll_interval' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Perform Now
    |--------------------------------------------------------------------------
    |
    | Whether the page may promote a delayed job onto its ready queue. Turn
    | this off for a read-only page; the routes are not registered at all.
    |
    */

    'perform_now' => true,

];
