<?php

namespace BoringO11y\HorizonDelayedJobs\Http\Controllers;

use BoringO11y\HorizonDelayedJobs\PerformNow;
use Illuminate\Http\Request;

class PerformNowController
{
    public function __construct(protected PerformNow $performer)
    {
    }

    /**
     * Promote one delayed job onto its ready queue.
     *
     * A job that is not in any delayed set is a 404 rather than a silent
     * success: by the time someone presses the button the backoff may have
     * expired on its own, and the page should say so instead of implying it
     * did something.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $id)
    {
        $performed = $this->performer->perform(
            $id,
            $request->input('connection'),
            $request->input('queue')
        );

        return response()->json(
            ['performed' => $performed], $performed ? 200 : 404
        );
    }

    /**
     * Promote each of the given delayed jobs.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMany(Request $request)
    {
        $performed = $this->performer->performMany(
            (array) $request->input('ids', [])
        );

        return response()->json([
            'performed' => $performed,
            'count' => count($performed),
        ]);
    }
}
