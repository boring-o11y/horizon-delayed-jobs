<?php

namespace BoringO11y\HorizonDelayedJobs\Http\Controllers;

use BoringO11y\HorizonDelayedJobs\DelayedJobs;
use Illuminate\Http\Request;

class DelayedJobsController
{
    public function __construct(protected DelayedJobs $jobs) {}

    /**
     * List the jobs currently waiting on a delay.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request)
    {
        $type = $request->query('type');
        $search = $request->query('search');
        $queue = $request->query('queue');
        $perPage = $request->query('per_page');

        $result = $this->jobs->paginate([
            'type' => in_array($type, ['retries', 'scheduled'], true) ? $type : null,
            'search' => is_string($search) ? $search : null,
            'queue' => is_string($queue) ? $queue : null,
            'page' => (int) $request->query('page', 1),
            'per_page' => is_numeric($perPage) ? (int) $perPage : null,
        ]);

        return [
            'jobs' => $result['jobs'],
            'total' => $result['total'],
            'matching' => $result['matching'],
            'truncated' => $result['truncated'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'queues' => $this->jobs->queueNames(),
        ];
    }
}
