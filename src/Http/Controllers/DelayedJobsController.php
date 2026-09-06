<?php

namespace BoringO11y\HorizonDelayedJobs\Http\Controllers;

use BoringO11y\HorizonDelayedJobs\DelayedJobs;
use Illuminate\Http\Request;

class DelayedJobsController
{
    public function __construct(protected DelayedJobs $jobs)
    {
    }

    /**
     * List the jobs currently waiting on a delay.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function index(Request $request)
    {
        $type = $request->query('type');

        $result = $this->jobs->paginate([
            'type' => in_array($type, ['retries', 'scheduled'], true) ? $type : null,
            'search' => $request->query('search'),
            'queue' => $request->query('queue'),
            'page' => (int) $request->query('page', 1),
            'per_page' => $request->query('per_page'),
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
